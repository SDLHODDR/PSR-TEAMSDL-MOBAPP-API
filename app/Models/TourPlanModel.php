<?php

declare(strict_types=1);

namespace App\Models;

use SDL\Framework\Database;
use SDL\Framework\Model;
use InvalidArgumentException;

/** Tour-plan persistence compatible with the legacy SFM tour-plan flow. */
final class TourPlanModel extends Model
{
    public function __construct(Database $db)
    {
        parent::__construct($db);
    }

    public function listByMonth(int $employeeId, string $month): array
    {
        $dataMonth = $this->dataMonth($month);
        $rows = $this->fetchAll(<<<'SQL'
            SELECT p.ID AS PLAN_ID, p.TOUR_MONTH, p.STATUS AS PLAN_STATUS,
                   d.ID AS DETAIL_ID, d.TOUR_DATE, d.ACT_TYPE, d.ROUTE_ID,
                   d.TOUR_HQ, d.JT_EMP_ID, d.TOWN, d.REMARK, d.STATUS AS DETAIL_STATUS,
                   a.ACT_DESC,
                   TO_CHAR(d.TOUR_DATE, 'YYYY-MM-DD') AS TOUR_DATE_TEXT,
                   TO_CHAR(p.TOUR_MONTH, 'YYYY-MM') AS TOUR_MONTH_TEXT
            FROM SFM_TOUR_PLANS p
            LEFT JOIN SFM_TOUR_PLANS_DET d ON d.TOUR_ID = p.ID
            LEFT JOIN SFM_ACTIVITY_TYPE a ON a.ACT_TYPE = d.ACT_TYPE
            WHERE p.EMP_ID = :employee_id
              AND p.TOUR_MONTH = LAST_DAY(TO_DATE(:month, 'YYYY-MM'))
            ORDER BY d.TOUR_DATE, d.ROUTE_ID
            SQL, ['employee_id' => $employeeId, 'month' => $dataMonth]);

        $plan = $rows[0] ?? null;
        return [
            'month' => $month,
            'data_month' => $dataMonth,
            'plan_id' => $plan === null ? null : (int) $plan['PLAN_ID'],
            'status' => $plan['PLAN_STATUS'] ?? null,
            'can_submit' => $plan !== null && in_array((string) $plan['PLAN_STATUS'], ['N', 'R'], true),
            'days' => array_map(static fn (array $row): array => [
                'id' => (int) $row['DETAIL_ID'],
                'date' => $row['TOUR_DATE_TEXT'],
                'activity_type' => $row['ACT_TYPE'],
                'activity_description' => $row['ACT_DESC'],
                'route_id' => $row['ROUTE_ID'] === null ? null : (int) $row['ROUTE_ID'],
                'tour_hq' => $row['TOUR_HQ'] === null ? null : (int) $row['TOUR_HQ'],
                'joint_employee_id' => $row['JT_EMP_ID'] === null ? null : (int) $row['JT_EMP_ID'],
                'town' => $row['TOWN'],
                'remark' => $row['REMARK'],
                'status' => $row['DETAIL_STATUS'],
            ], array_values(array_filter($rows, static fn (array $row): bool => $row['DETAIL_ID'] !== null))),
        ];
    }

    public function saveDraft(int $employeeId, string $loginId, array $input): array
    {
        $month = $this->month((string) ($input['month'] ?? ''));
        $date = $this->date((string) ($input['date'] ?? $input['tour_date'] ?? ''));
        $activity = strtoupper(trim((string) ($input['activity_type'] ?? $input['activityType'] ?? '')));
        if ($activity === '') throw new InvalidArgumentException('activity_type is required.');

        return $this->transaction(function () use ($employeeId, $loginId, $month, $date, $activity, $input): array {
            $plan = $this->fetchOne(
                'SELECT ID, STATUS FROM SFM_TOUR_PLANS WHERE EMP_ID = :employee_id AND TOUR_MONTH = LAST_DAY(TO_DATE(:month, \'YYYY-MM\')) FOR UPDATE',
                ['employee_id' => $employeeId, 'month' => $this->dataMonth($month)]
            );
            if ($plan !== null && in_array((string) $plan['STATUS'], ['T', 'A'], true)) {
                throw new InvalidArgumentException('This monthly tour plan is already submitted or authorized.');
            }

            $planId = $plan === null
                ? $this->executeReturning(<<<'SQL'
                    INSERT INTO SFM_TOUR_PLANS (ID, EMP_ID, TOUR_MONTH, STATUS, TASK_ID, AUTH_BY, AUTH_ON, CHG_ON, CHG_BY)
                    VALUES (NULL, :employee_id, LAST_DAY(TO_DATE(:month, 'YYYY-MM')), 'N', NULL, NULL, NULL, SYSDATE, :login_id)
                    RETURNING ID INTO :return_id
                    SQL, ['employee_id' => $employeeId, 'month' => $this->dataMonth($month), 'login_id' => $loginId], ':return_id')
                : (string) $plan['ID'];

            $this->execute('DELETE FROM SFM_TOUR_PLANS_DET WHERE TOUR_ID = :plan_id AND TOUR_DATE = TO_DATE(:tour_date, \'YYYY-MM-DD\')', ['plan_id' => $planId, 'tour_date' => $this->dataDate($date)]);

            $routeId = $this->intOrNull($input['route_id'] ?? $input['routeId'] ?? null);
            $hq = $this->intOrNull($input['tour_hq'] ?? $input['tourHq'] ?? $input['hq_id'] ?? null);
            $joint = $this->intOrNull($input['joint_employee_id'] ?? $input['jointEmployeeId'] ?? null);
            $town = trim((string) ($input['town'] ?? $input['town_desc'] ?? ''));
            if ($routeId !== null && $town === '') {
                $route = $this->fetchOne('SELECT TOWN_ID, HQ_ID FROM SFM_ROUTE_PLANS WHERE ROUTE_SEQ = :route_id AND STATUS = \'A\' AND (:hq_id IS NULL OR HQ_ID = :hq_id) AND ROWNUM = 1', ['route_id' => $routeId, 'hq_id' => $hq]);
                if ($route === null) throw new InvalidArgumentException('The selected route is not available.');
                $hq ??= (int) $route['HQ_ID'];
                $townRow = $this->fetchOne('SELECT TOWN_DESC FROM SFM_GEO_HQ_TOWNS WHERE TOWN_ID = :town_id AND HQ_ID = :hq_id', ['town_id' => $route['TOWN_ID'], 'hq_id' => $hq]);
                $town = (string) ($townRow['TOWN_DESC'] ?? '');
            }

            $this->execute(<<<'SQL'
                INSERT INTO SFM_TOUR_PLANS_DET
                    (ID, TOUR_ID, TOUR_DATE, ACT_TYPE, ROUTE_ID, EXP_BUSS, REMARK, TOUR_HQ, JT_EMP_ID, TOWN, STATUS, CHG_ON, CHG_BY)
                VALUES
                    (NULL, :plan_id, TO_DATE(:tour_date, 'YYYY-MM-DD'), :activity_type, :route_id, NULL, :remark, :tour_hq, :joint_employee_id, :town, 'N', SYSDATE, :login_id)
                SQL, ['plan_id' => $planId, 'tour_date' => $this->dataDate($date), 'activity_type' => $activity, 'route_id' => $routeId, 'remark' => (string) ($input['remark'] ?? $input['remarks'] ?? ''), 'tour_hq' => $hq, 'joint_employee_id' => $joint, 'town' => $town, 'login_id' => $loginId]);

            return ['plan_id' => (int) $planId, 'date' => $date, 'status' => 'N'];
        });
    }

    public function submit(int $employeeId, string $loginId, string $month): array
    {
        $month = $this->month($month);
        return $this->transaction(function () use ($employeeId, $loginId, $month): array {
            $plan = $this->fetchOne('SELECT ID, STATUS FROM SFM_TOUR_PLANS WHERE EMP_ID = :employee_id AND TOUR_MONTH = LAST_DAY(TO_DATE(:month, \'YYYY-MM\')) FOR UPDATE', ['employee_id' => $employeeId, 'month' => $this->dataMonth($month)]);
            if ($plan === null) throw new InvalidArgumentException('No draft tour plan exists for this month.');
            if (in_array((string) $plan['STATUS'], ['T', 'A'], true)) throw new InvalidArgumentException('This monthly tour plan has already been submitted.');
            $count = $this->fetchOne('SELECT COUNT(1) AS CNT FROM SFM_TOUR_PLANS_DET WHERE TOUR_ID = :plan_id', ['plan_id' => $plan['ID']]);
            if ((int) ($count['CNT'] ?? 0) === 0) throw new InvalidArgumentException('Add at least one day before submitting the monthly plan.');
            $manager = $this->fetchOne('SELECT MANAGER_ID FROM SFM_EMP_MGR WHERE EMP_ID = :employee_id AND TRUNC(SYSDATE) BETWEEN EFFEC_FROM AND NVL(EFFEC_TO, DATE \'3000-01-01\')', ['employee_id' => $employeeId]);
            if ($manager === null || empty($manager['MANAGER_ID'])) throw new InvalidArgumentException('No active manager is configured for this employee.');
            $task = $this->fetchOne('SELECT ID FROM SFM_USER_TASKS WHERE TASK_GROUP = \'TourAuth\' AND TRAN_ID = :plan_id AND EMP_ID = :employee_id AND EMP_ID_FOR = :manager_id AND STATUS = \'O\'', ['plan_id' => $plan['ID'], 'employee_id' => $employeeId, 'manager_id' => $manager['MANAGER_ID']]);
            $taskId = $task['ID'] ?? $this->executeReturning(<<<'SQL'
                INSERT INTO SFM_USER_TASKS (ID, TASK_DATE, EXPIRY_DATE, TASK_GROUP, TRAN_ID, TRAN_DESC, EMP_ID, EMP_ID_FOR, STATUS, AUTH_ON, AUTH_BY, REMARKS)
                VALUES (NULL, SYSDATE, SYSDATE + 2, 'TourAuth', :plan_id, :description, :employee_id, :manager_id, 'O', NULL, NULL, NULL)
                RETURNING ID INTO :return_id
                SQL, ['plan_id' => $plan['ID'], 'description' => $month, 'employee_id' => $employeeId, 'manager_id' => $manager['MANAGER_ID']], ':return_id');
            $this->execute('UPDATE SFM_TOUR_PLANS SET STATUS = \'T\', TASK_ID = :task_id, CHG_ON = SYSDATE, CHG_BY = :login_id WHERE ID = :plan_id AND EMP_ID = :employee_id', ['task_id' => $taskId, 'login_id' => $loginId, 'plan_id' => $plan['ID'], 'employee_id' => $employeeId]);
            return ['plan_id' => (int) $plan['ID'], 'status' => 'T', 'task_id' => (int) $taskId];
        });
    }

    private function month(string $value): string
    {
        if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $value)) throw new InvalidArgumentException('month must use YYYY-MM format.');
        return $value;
    }
    private function date(string $value): string
    {
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if (!$parsed || $parsed->format('Y-m-d') !== $value) throw new InvalidArgumentException('date must use YYYY-MM-DD format.');
        return $value;
    }
    /** Explicit API months are already data months; only the controller's default is offset. */
    private function dataMonth(string $month): string { return $month; }
    private function dataDate(string $date): string { return $date; }
    private function intOrNull(mixed $value): ?int { return $value === null || $value === '' ? null : (int) $value; }
}
