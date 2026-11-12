<?php

declare(strict_types=1);

namespace App\Models;

use InvalidArgumentException;
use SDL\Framework\Database;
use SDL\Framework\Model;

final class NonFieldActivityModel extends Model
{
    private const EXCLUDED_ACTIVITY_TYPES = ['F', 'H', 'L', 'S'];

    public function __construct(Database $db)
    {
        parent::__construct($db);
    }

    public function activityOptions(): array
    {
        $rows = $this->fetchAll(<<<'SQL'
            SELECT ACT_TYPE, ACT_DESC
            FROM SFM_ACTIVITY_TYPE
            WHERE STATUS = 'A'
              AND ACT_TYPE NOT IN ('H', 'L', 'S', 'F')
            ORDER BY ACT_DESC
            SQL);

        return [
            'activities' => array_map(static fn (array $row): array => [
                'activity_type' => (string) $row['ACT_TYPE'],
                'activity_description' => (string) $row['ACT_DESC'],
            ], $rows),
        ];
    }

    public function listForEmployee(int $employeeId, ?int $activityId = null): array
    {
        $activityFilter = $activityId === null ? '' : 'AND a.ID = :activity_id';
        $params = ['employee_id' => $employeeId];
        if ($activityId !== null) {
            $params['activity_id'] = $activityId;
        }

        $rows = $this->fetchAll(<<<SQL
            SELECT a.ID AS ACTIVITY_ID,
                   TO_CHAR(a.ASON_DATE, 'YYYY-MM-DD') AS ACTIVITY_DATE,
                   TO_CHAR(a.ASON_DATE, 'DD-Mon-YYYY') AS ACTIVITY_DATE_LABEL,
                   a.ACT_TYPE AS ACTIVITY_TYPE,
                   t.ACT_DESC AS ACTIVITY_DESCRIPTION,
                   a.REMARKS,
                   a.STATUS AS STATUS_CODE
            FROM SFM_NONFLD_ACTIVITY a
            INNER JOIN SFM_ACTIVITY_TYPE t ON t.ACT_TYPE = a.ACT_TYPE
            WHERE a.EMP_ID = :employee_id
              AND TRUNC(a.ASON_DATE) BETWEEN ADD_MONTHS(TRUNC(SYSDATE), -3)
                                         AND TRUNC(SYSDATE)
              AND a.ACT_TYPE NOT IN ('F', 'S', 'H')
              $activityFilter
            ORDER BY a.ASON_DATE DESC, a.ID DESC
            SQL, $params);

        return [
            'activities' => array_map(fn (array $row): array => [
                'activity_id' => (int) $row['ACTIVITY_ID'],
                'date' => (string) $row['ACTIVITY_DATE'],
                'date_label' => (string) $row['ACTIVITY_DATE_LABEL'],
                'activity_type' => (string) $row['ACTIVITY_TYPE'],
                'activity' => (string) $row['ACTIVITY_DESCRIPTION'],
                'remarks' => (string) ($row['REMARKS'] ?? ''),
                'status_code' => (string) $row['STATUS_CODE'],
                'status' => $this->statusLabel((string) $row['STATUS_CODE']),
                'can_edit' => $row['STATUS_CODE'] !== 'A',
            ], $rows),
        ];
    }

    public function save(int $employeeId, string $loginId, array $input): array
    {
        $activityType = strtoupper(trim((string) ($input['activity_type'] ?? '')));
        $activityDate = trim((string) ($input['date'] ?? ''));
        $remarks = trim((string) ($input['remarks'] ?? ''));
        $activityId = $this->positiveId($input['activity_id'] ?? null);

        if ($activityType === '') {
            throw new InvalidArgumentException('activity_type is required.');
        }
        if (!in_array($activityType, self::EXCLUDED_ACTIVITY_TYPES, true)) {
            $option = $this->fetchOne(<<<'SQL'
                SELECT ACT_TYPE
                FROM SFM_ACTIVITY_TYPE
                WHERE ACT_TYPE = :activity_type
                  AND STATUS = 'A'
                  AND ACT_TYPE NOT IN ('H', 'L', 'S', 'F')
                SQL, ['activity_type' => $activityType]);
            if ($option === null) {
                throw new InvalidArgumentException('The selected activity is not available.');
            }
        } else {
            throw new InvalidArgumentException('The selected activity is not available for non-field reporting.');
        }

        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $activityDate);
        if ($date === false || $date->format('Y-m-d') !== $activityDate) {
            throw new InvalidArgumentException('date must use YYYY-MM-DD format.');
        }
        $dateIsAllowed = $this->fetchOne(<<<'SQL'
            SELECT CASE
                     WHEN TO_DATE(:activity_date, 'YYYY-MM-DD')
                          BETWEEN ADD_MONTHS(TRUNC(SYSDATE), -3) AND TRUNC(SYSDATE)
                     THEN 1 ELSE 0
                   END AS IS_ALLOWED
            FROM DUAL
            SQL, ['activity_date' => $activityDate]);
        if ((int) ($dateIsAllowed['IS_ALLOWED'] ?? 0) !== 1) {
            throw new InvalidArgumentException('The activity date must be within the last three months and cannot be in the future.');
        }
        if ($remarks === '') {
            throw new InvalidArgumentException('remarks is required.');
        }
        if (strlen($remarks) > 500) {
            throw new InvalidArgumentException('remarks must be no longer than 500 characters.');
        }

        $manager = $this->fetchOne(<<<'SQL'
            SELECT MANAGER_ID
            FROM SFM_EMP_MGR
            WHERE EMP_ID = :employee_id
              AND TRUNC(SYSDATE) BETWEEN TRUNC(EFFEC_FROM)
                  AND NVL(TRUNC(EFFEC_TO), DATE '3000-01-01')
            ORDER BY EFFEC_FROM DESC
            FETCH FIRST 1 ROWS ONLY
            SQL, ['employee_id' => $employeeId]);
        if ($manager === null || empty($manager['MANAGER_ID'])) {
            throw new InvalidArgumentException('No active manager is configured for this employee.');
        }
        $managerId = (int) $manager['MANAGER_ID'];

        return $this->transaction(function () use (
            $employeeId,
            $loginId,
            $activityType,
            $activityDate,
            $remarks,
            $activityId,
            $managerId
        ): array {
            if ($activityId === null) {
                $activityId = (int) $this->executeReturning(<<<'SQL'
                    INSERT INTO SFM_NONFLD_ACTIVITY (
                        ID, EMP_ID, ASON_DATE, ACT_TYPE, STATUS, REMARKS,
                        INTIME, CHG_ON, CHG_BY
                    ) VALUES (
                        NULL, :employee_id, TO_DATE(:activity_date, 'YYYY-MM-DD'),
                        :activity_type, 'T', :remarks, NULL, SYSDATE, :login_id
                    )
                    RETURNING ID INTO :return_id
                    SQL, [
                        'employee_id' => $employeeId,
                        'activity_date' => $activityDate,
                        'activity_type' => $activityType,
                        'remarks' => $remarks,
                        'login_id' => $loginId,
                    ], ':return_id');
            } else {
                $existing = $this->fetchOne(<<<'SQL'
                    SELECT ID, STATUS
                    FROM SFM_NONFLD_ACTIVITY
                    WHERE ID = :activity_id
                      AND EMP_ID = :employee_id
                    FOR UPDATE
                    SQL, ['activity_id' => $activityId, 'employee_id' => $employeeId]);
                if ($existing === null) {
                    throw new InvalidArgumentException('The non-field activity was not found.');
                }
                if ($existing['STATUS'] === 'A') {
                    throw new InvalidArgumentException('A confirmed activity cannot be edited.');
                }

                $this->execute(<<<'SQL'
                    UPDATE SFM_NONFLD_ACTIVITY
                    SET STATUS = 'T',
                        CHG_BY = :login_id,
                        CHG_ON = SYSDATE,
                        ACT_TYPE = :activity_type,
                        REMARKS = :remarks
                    WHERE ID = :activity_id
                      AND EMP_ID = :employee_id
                      AND STATUS <> 'A'
                    SQL, [
                        'login_id' => $loginId,
                        'activity_type' => $activityType,
                        'remarks' => $remarks,
                        'activity_id' => $activityId,
                        'employee_id' => $employeeId,
                    ]);
            }

            $task = $this->fetchOne(<<<'SQL'
                SELECT ID
                FROM SFM_USER_TASKS
                WHERE TASK_GROUP = 'NonFldAuth'
                  AND TRAN_ID = :activity_id
                  AND EMP_ID = :employee_id
                  AND EMP_ID_FOR = :manager_id
                  AND STATUS = 'O'
                FETCH FIRST 1 ROWS ONLY
                SQL, [
                    'activity_id' => $activityId,
                    'employee_id' => $employeeId,
                    'manager_id' => $managerId,
                ]);
            $taskId = $task['ID'] ?? $this->executeReturning(<<<'SQL'
                INSERT INTO SFM_USER_TASKS (
                    ID, TASK_DATE, EXPIRY_DATE, TASK_GROUP, TRAN_ID, TRAN_DESC,
                    EMP_ID, EMP_ID_FOR, STATUS, AUTH_ON, AUTH_BY, REMARKS
                ) VALUES (
                    NULL, SYSDATE, SYSDATE + 2, 'NonFldAuth', :activity_id,
                    :description, :employee_id, :manager_id, 'O', NULL, NULL, NULL
                )
                RETURNING ID INTO :return_id
                SQL, [
                    'activity_id' => $activityId,
                    'description' => 'Non Field Activities Authorisation',
                    'employee_id' => $employeeId,
                    'manager_id' => $managerId,
                ], ':return_id');

            return [
                'activity_id' => (int) $activityId,
                'status_code' => 'T',
                'task_id' => (int) $taskId,
            ];
        });
    }

    private function positiveId(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_string($value) && !is_int($value)) {
            throw new InvalidArgumentException('activity_id must be a positive integer.');
        }
        $text = (string) $value;
        if (!ctype_digit($text) || (int) $text <= 0) {
            throw new InvalidArgumentException('activity_id must be a positive integer.');
        }
        return (int) $text;
    }

    private function statusLabel(string $status): string
    {
        return match ($status) {
            'T' => 'Pending',
            'A' => 'Confirmed',
            'X' => 'Rejected',
            default => $status,
        };
    }
}
