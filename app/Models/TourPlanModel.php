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

    public function activityOptions(): array
    {
        $rows = $this->fetchAll(<<<'SQL'
            SELECT ACT_TYPE, ACT_DESC
            FROM SFM_ACTIVITY_TYPE
            WHERE STATUS = 'A'
              AND UPPER(TRIM(ACT_DESC)) NOT IN ('REMOVE', 'LEAVE', 'HOLIDAY', 'SUNDAY')
            ORDER BY ACT_DESC
            SQL);

        return [
            'activities' => array_map(static fn (array $row): array => [
                'activity_type' => (string) $row['ACT_TYPE'],
                'activity_description' => (string) $row['ACT_DESC'],
            ], $rows),
        ];
    }

    public function routeOptions(int $employeeId, string $date): array
    {
        $date = $this->date($date);
        $rows = $this->fetchAll(<<<'SQL'
            SELECT DISTINCT r.ROUTE_SEQ AS ROUTE_ID, r.HQ_ID AS TOUR_HQ,
                   t.TOWN_DESC
            FROM SFM_ROUTE_PLANS r
            INNER JOIN SFM_GEO_HQ_TOWNS t ON r.TOWN_ID = t.TOWN_ID
            WHERE r.STATUS = 'A'
              AND r.HQ_ID = get_empid_hqid(
                  :employee_id, TO_DATE(:tour_date, 'YYYY-MM-DD')
              )
              AND r.DIVSN_ID IN (
                  SELECT DIVSN_ID
                  FROM SFM_EMP_DIVISIONS
                  WHERE EMP_ID = :employee_id
                    AND TO_DATE(:tour_date, 'YYYY-MM-DD')
                        BETWEEN TRUNC(EFFEC_FROM)
                        AND NVL(TRUNC(EFFEC_TO), DATE '3000-01-01')
              )
            ORDER BY r.ROUTE_SEQ
            SQL, ['employee_id' => $employeeId, 'tour_date' => $date]);

        $countRows = $this->fetchAll(<<<'SQL'
            SELECT r.ROUTE_SEQ AS ROUTE_ID, r.HQ_ID AS TOUR_HQ,
                   c.CTYP_ID, c.CTYP_DESC, COUNT(1) AS CUST_COUNT
            FROM SFM_ROUTE_PLANS r
            INNER JOIN SFM_ROUTE_PLANS_DET rd ON rd.ROUTE_ID = r.ID
            INNER JOIN SFM_CUST_TYPES c ON c.CTYP_ID = rd.CUST_TYPE
            WHERE r.STATUS = 'A'
              AND r.HQ_ID = get_empid_hqid(
                  :employee_id, TO_DATE(:tour_date, 'YYYY-MM-DD')
              )
              AND r.DIVSN_ID IN (
                  SELECT DIVSN_ID
                  FROM SFM_EMP_DIVISIONS
                  WHERE EMP_ID = :employee_id
                    AND TO_DATE(:tour_date, 'YYYY-MM-DD')
                        BETWEEN TRUNC(EFFEC_FROM)
                        AND NVL(TRUNC(EFFEC_TO), DATE '3000-01-01')
              )
              AND TO_DATE(:tour_date, 'YYYY-MM-DD')
                  BETWEEN TRUNC(rd.EFFEC_FROM)
                  AND NVL(TRUNC(rd.EFFEC_TO), DATE '3000-01-01')
            GROUP BY r.ROUTE_SEQ, r.HQ_ID, c.CTYP_ID, c.CTYP_DESC
            ORDER BY r.ROUTE_SEQ, c.CTYP_ID
            SQL, ['employee_id' => $employeeId, 'tour_date' => $date]);

        $customerCountsByRoute = [];
        foreach ($countRows as $countRow) {
            $key = (string) $countRow['ROUTE_ID'] . ':' . (string) $countRow['TOUR_HQ'];
            $customerCountsByRoute[$key][] = [
                'customer_type_id' => (int) $countRow['CTYP_ID'],
                'customer_type' => $countRow['CTYP_DESC'],
                'count' => (int) $countRow['CUST_COUNT'],
            ];
        }

        return [
            'date' => $date,
            'routes' => array_map(static function (array $row) use ($customerCountsByRoute): array {
                $key = (string) $row['ROUTE_ID'] . ':' . (string) $row['TOUR_HQ'];
                return [
                    'route_id' => (int) $row['ROUTE_ID'],
                    'tour_hq' => (int) $row['TOUR_HQ'],
                    'town' => (string) $row['TOWN_DESC'],
                    'customer_counts' => $customerCountsByRoute[$key] ?? [],
                ];
            }, $rows),
        ];
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

        $countRows = $this->fetchAll(<<<'SQL'
            WITH plan_details AS (
                SELECT d.ID AS DETAIL_ID, d.ROUTE_ID, d.TOUR_HQ, d.JT_EMP_ID
                FROM SFM_TOUR_PLANS p
                INNER JOIN SFM_TOUR_PLANS_DET d ON d.TOUR_ID = p.ID
                WHERE p.EMP_ID = :employee_id
                  AND p.TOUR_MONTH = LAST_DAY(TO_DATE(:month, 'YYYY-MM'))
            ),
            employee_divisions AS (
                SELECT DIVSN_ID
                FROM SFM_EMP_DIVISIONS
                WHERE EMP_ID = :employee_id
                  AND TO_DATE(:month, 'YYYY-MM') BETWEEN TRUNC(EFFEC_FROM)
                      AND NVL(TRUNC(EFFEC_TO), DATE '3000-01-01')
            )
            SELECT detail_counts.DETAIL_ID, detail_counts.CTYP_ID,
                   detail_counts.CTYP_DESC, detail_counts.CUST_COUNT
            FROM (
                SELECT d.DETAIL_ID, c.CTYP_ID, c.CTYP_DESC, COUNT(1) AS CUST_COUNT
                FROM plan_details d
                INNER JOIN SFM_ROUTE_PLANS r
                    ON r.HQ_ID = d.TOUR_HQ AND r.ROUTE_SEQ = d.ROUTE_ID
                INNER JOIN SFM_ROUTE_PLANS_DET rd ON rd.ROUTE_ID = r.ID
                INNER JOIN SFM_CUST_TYPES c ON c.CTYP_ID = rd.CUST_TYPE
                WHERE d.ROUTE_ID IS NOT NULL
                  AND NVL(d.TOUR_HQ, 0) <> 0
                  AND TO_DATE(:month, 'YYYY-MM') BETWEEN rd.EFFEC_FROM
                      AND NVL(rd.EFFEC_TO, DATE '3000-01-01')
                  AND r.DIVSN_ID IN (SELECT DIVSN_ID FROM employee_divisions)
                GROUP BY d.DETAIL_ID, c.CTYP_ID, c.CTYP_DESC

                UNION ALL

                SELECT d.DETAIL_ID, c.CTYP_ID, c.CTYP_DESC, SUM(s.CUST_COUNT) AS CUST_COUNT
                FROM plan_details d
                INNER JOIN SFM_ROUTE_PLANS_SUMMARY s
                    ON s.HQ_ID = get_empid_hqid(NVL(d.JT_EMP_ID, :employee_id),
                                                TO_DATE(:month, 'YYYY-MM'))
                   AND s.ROUTE_SEQ = d.ROUTE_ID
                INNER JOIN SFM_CUST_TYPES c ON c.CTYP_ID = s.CUST_TYPE
                WHERE d.ROUTE_ID IS NOT NULL
                  AND NVL(d.TOUR_HQ, 0) = 0
                  AND s.DIVSN_ID IN (SELECT DIVSN_ID FROM employee_divisions)
                GROUP BY d.DETAIL_ID, c.CTYP_ID, c.CTYP_DESC
            ) detail_counts
            ORDER BY detail_counts.DETAIL_ID, detail_counts.CTYP_ID
            SQL, ['employee_id' => $employeeId, 'month' => $dataMonth]);

        $customerCountsByDetail = [];
        foreach ($countRows as $countRow) {
            $detailId = (string) $countRow['DETAIL_ID'];
            $customerCountsByDetail[$detailId][] = [
                'customer_type_id' => (int) $countRow['CTYP_ID'],
                'customer_type' => $countRow['CTYP_DESC'],
                'count' => (int) $countRow['CUST_COUNT'],
            ];
        }

        $plan = $rows[0] ?? null;
        $days = [];
        $calendar = [];
        foreach ($rows as $row) {
            if ($row['DETAIL_ID'] === null) {
                continue;
            }

            $customerCounts = $customerCountsByDetail[(string) $row['DETAIL_ID']] ?? [];
            $totalCustomerCount = array_sum(array_column($customerCounts, 'count'));
            $days[] = [
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
                'customer_counts' => $customerCounts,
                'total_customer_count' => $totalCustomerCount,
            ];

            $status = (string) ($row['PLAN_STATUS'] ?? '');
            $activityType = (string) ($row['ACT_TYPE'] ?? '');
            $color = $activityType === 'S'
                ? '#cccccc'
                : (['N' => '#ccccff', 'A' => '#3cb878', 'T' => '#cccc66'][$status] ?? null);
            $route = $row['ROUTE_ID'] === null
                ? (string) ($row['REMARK'] ?? '')
                : (string) $row['ROUTE_ID'] . ' - ' . (string) ($row['TOWN'] ?? '');
            $calendar[] = [
                'title' => (string) ($row['ACT_DESC'] ?? '') . ' : ' . $route,
                'start' => $row['TOUR_DATE_TEXT'],
                'color' => $color,
                'flag' => true,
                'customer_counts' => $customerCounts,
                'total_customer_count' => $totalCustomerCount,
            ];
        }

        return [
            'month' => $month,
            'data_month' => $dataMonth,
            'plan_id' => $plan === null ? null : (int) $plan['PLAN_ID'],
            'status' => $plan['PLAN_STATUS'] ?? null,
            'can_submit' => $plan !== null && in_array((string) $plan['PLAN_STATUS'], ['N', 'R'], true),
            'days' => $days,
            'calendar' => $calendar,
        ];
    }

    public function saveDraft(int $employeeId, string $loginId, array $input): array
    {
        $month = $this->month((string) ($input['month'] ?? ''));
        $date = $this->date((string) ($input['date'] ?? $input['tour_date'] ?? ''));
        if (substr($date, 0, 7) !== $month) {
            throw new InvalidArgumentException('The tour-plan date must be in the requested month.');
        }
        if ((int) (new \DateTimeImmutable($date))->format('N') === 7) {
            throw new InvalidArgumentException('Sunday is a non-working day and cannot have a tour plan.');
        }

        return $this->transaction(function () use ($employeeId, $loginId, $month, $date, $input): array {
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

            $this->persistDay($planId, $employeeId, $loginId, $date, $input);

            return ['plan_id' => (int) $planId, 'date' => $date, 'status' => 'N'];
        });
    }

    public function submit(int $employeeId, string $loginId, string $month, ?array $days = null): array
    {
        $month = $this->month($month);
        $normalizedDays = $days === null ? null : $this->normalizeDays($month, $days);

        return $this->transaction(function () use ($employeeId, $loginId, $month, $normalizedDays): array {
            $plan = $this->fetchOne(
                'SELECT ID, STATUS 
                FROM SFM_TOUR_PLANS 
                WHERE 
                    EMP_ID = :employee_id 
                    AND TOUR_MONTH = LAST_DAY(TO_DATE(:month, \'YYYY-MM\')) 
                FOR UPDATE', ['employee_id' => $employeeId, 'month' => $this->dataMonth($month)]);
            
            if ($plan !== null && in_array((string) $plan['STATUS'], ['T', 'A'], true)) throw new InvalidArgumentException('This monthly tour plan has already been submitted.');

            if ($normalizedDays !== null) {
                if ($plan === null) {
                    $planId = $this->executeReturning(<<<'SQL'
                        INSERT INTO SFM_TOUR_PLANS (
                            ID, 
                            EMP_ID, 
                            TOUR_MONTH, 
                            STATUS, 
                            TASK_ID, 
                            AUTH_BY, 
                            AUTH_ON, 
                            CHG_ON, 
                            CHG_BY)
                        VALUES (
                            NULL, 
                            :employee_id, 
                            LAST_DAY(TO_DATE(:month, 'YYYY-MM')), 
                            'N', 
                            NULL, 
                            NULL, 
                            NULL, 
                            SYSDATE, 
                            :login_id)
                        RETURNING ID INTO :return_id
                        SQL, [
                            'employee_id' => $employeeId, 
                            'month' => $this->dataMonth($month), 
                            'login_id' => $loginId], 
                            ':return_id');
                } else {
                    $planId = (string) $plan['ID'];
                }
                
                $this->execute('DELETE FROM SFM_TOUR_PLANS_DET WHERE TOUR_ID = :plan_id', 
                ['plan_id' => $planId]);
                
                foreach ($normalizedDays as $day) {
                    $this->persistDay($planId, $employeeId, $loginId, $day['date'], $day);
                }

                $plan = ['ID' => $planId, 'STATUS' => 'N'];
            } elseif ($plan === null) {
                throw new InvalidArgumentException('No draft tour plan exists for this month.');
            }

            $count = $this->fetchOne(
                'SELECT COUNT(1) AS CNT 
                FROM SFM_TOUR_PLANS_DET 
                WHERE TOUR_ID = :plan_id', 
                ['plan_id' => $plan['ID']]);
            
            if ((int) ($count['CNT'] ?? 0) === 0) 
                throw new InvalidArgumentException('Add at least one day before submitting the monthly plan.');
            
            $manager = $this->fetchOne('
                SELECT MANAGER_ID 
                FROM SFM_EMP_MGR 
                WHERE 
                    EMP_ID = :employee_id 
                    AND TRUNC(SYSDATE) 
                    BETWEEN EFFEC_FROM AND NVL(EFFEC_TO, DATE \'3000-01-01\')', 
                ['employee_id' => $employeeId]);
            
            if ($manager === null || empty($manager['MANAGER_ID'])) 
                throw new InvalidArgumentException('No active manager is configured for this employee.');
            
            $task = $this->fetchOne('
                SELECT ID 
                FROM SFM_USER_TASKS 
                WHERE 
                    TASK_GROUP = \'TourAuth\' 
                    AND TRAN_ID = :plan_id 
                    AND EMP_ID = :employee_id 
                    AND EMP_ID_FOR = :manager_id 
                    AND STATUS = \'O\'', 
                    [
                        'plan_id' => $plan['ID'], 
                        'employee_id' => $employeeId, 
                        'manager_id' => $manager['MANAGER_ID']
                    ]);

            $taskId = $task['ID'] ?? $this->executeReturning(<<<'SQL'
                INSERT INTO SFM_USER_TASKS (
                    ID, 
                    TASK_DATE, 
                    EXPIRY_DATE, 
                    TASK_GROUP, 
                    TRAN_ID, 
                    TRAN_DESC, 
                    EMP_ID, 
                    EMP_ID_FOR, 
                    STATUS, 
                    AUTH_ON, 
                    AUTH_BY, 
                    REMARKS)
                VALUES (
                    NULL, 
                    SYSDATE, 
                    SYSDATE + 2, 
                    'TourAuth', 
                    :plan_id, 
                    :description, 
                    :employee_id, 
                    :manager_id, 
                    'O', 
                    NULL, 
                    NULL, 
                    NULL)
                RETURNING ID INTO :return_id
                SQL, [
                    'plan_id' => $plan['ID'], 
                    'description' => $month, 
                    'employee_id' => $employeeId, 
                    'manager_id' => $manager['MANAGER_ID']
                ], ':return_id');
            
            $this->execute('
                UPDATE SFM_TOUR_PLANS 
                SET 
                    STATUS = \'T\', 
                    TASK_ID = :task_id, 
                    CHG_ON = SYSDATE, 
                    CHG_BY = :login_id 
                WHERE 
                    ID = :plan_id 
                    AND EMP_ID = :employee_id', 
                [
                    'task_id' => $taskId, 
                    'login_id' => $loginId, 
                    'plan_id' => $plan['ID'], 
                    'employee_id' => $employeeId
                ]);

            return ['plan_id' => (int) $plan['ID'], 'status' => 'T', 'task_id' => (int) $taskId, 'saved_days' => (int) ($count['CNT'] ?? 0)];
        });
    }

    private function persistDay(int|string $planId, int $employeeId, string $loginId, string $date, array $input): void
    {
        $activity = strtoupper(trim((string) ($input['activity_type'] ?? $input['activityType'] ?? '')));
        if ($activity === '') throw new InvalidArgumentException('activity_type is required.');
        $validActivity = $this->fetchOne(
            "SELECT ACT_TYPE FROM SFM_ACTIVITY_TYPE WHERE ACT_TYPE = :activity_type AND STATUS = 'A' AND UPPER(TRIM(ACT_DESC)) NOT IN ('REMOVE', 'LEAVE', 'HOLIDAY', 'SUNDAY')",
            ['activity_type' => $activity]
        );
        if ($validActivity === null) throw new InvalidArgumentException('The selected activity is not available.');

        $routeId = $this->intOrNull($input['route_id'] ?? $input['routeId'] ?? null);
        $hq = $this->intOrNull($input['tour_hq'] ?? $input['tourHq'] ?? $input['hq_id'] ?? null);
        $joint = $this->intOrNull($input['joint_employee_id'] ?? $input['jointEmployeeId'] ?? null);
        $town = '';
        $divisionId = null;
        if ($routeId !== null) {
            $route = $this->fetchOne(<<<'SQL'
                SELECT TOWN_ID, HQ_ID, DIVSN_ID
                FROM SFM_ROUTE_PLANS
                WHERE ROUTE_SEQ = :route_id
                  AND STATUS = 'A'
                  AND HQ_ID = get_empid_hqid(:employee_id, TO_DATE(:tour_date, 'YYYY-MM-DD'))
                  AND (:hq_id IS NULL OR HQ_ID = :hq_id)
                  AND DIVSN_ID IN (
                      SELECT DIVSN_ID
                      FROM SFM_EMP_DIVISIONS
                      WHERE EMP_ID = :employee_id
                        AND TO_DATE(:tour_date, 'YYYY-MM-DD')
                            BETWEEN TRUNC(EFFEC_FROM)
                            AND NVL(TRUNC(EFFEC_TO), DATE '3000-01-01')
                  )
                  AND ROWNUM = 1
                SQL, [
                    'route_id' => $routeId,
                    'employee_id' => $employeeId,
                    'tour_date' => $this->dataDate($date),
                    'hq_id' => $hq,
                ]);
            if ($route === null) throw new InvalidArgumentException('The selected route is not available for this employee and date.');
            $hq = (int) $route['HQ_ID'];
            $divisionId = (int) $route['DIVSN_ID'];
            $townRow = $this->fetchOne(
                'SELECT TOWN_DESC FROM SFM_GEO_HQ_TOWNS WHERE TOWN_ID = :town_id AND HQ_ID = :hq_id',
                ['town_id' => $route['TOWN_ID'], 'hq_id' => $hq]
            );
            $town = (string) ($townRow['TOWN_DESC'] ?? '');
        } elseif ($activity === 'F') {
            throw new InvalidArgumentException('A route is required for Field Work.');
        }
        if ($divisionId === null) {
            $division = $this->fetchOne(<<<'SQL'
                SELECT MIN(DIVSN_ID) AS DIVSN_ID
                FROM SFM_EMP_DIVISIONS
                WHERE EMP_ID = :employee_id
                  AND TO_DATE(:tour_date, 'YYYY-MM-DD')
                      BETWEEN TRUNC(EFFEC_FROM)
                      AND NVL(TRUNC(EFFEC_TO), DATE '3000-01-01')
                SQL, [
                    'employee_id' => $employeeId,
                    'tour_date' => $this->dataDate($date),
                ]);
            if ($division === null || $division['DIVSN_ID'] === null) {
                throw new InvalidArgumentException('No active division is configured for this employee and date.');
            }
            $divisionId = (int) $division['DIVSN_ID'];
        }

        $this->execute(<<<'SQL'
            INSERT INTO SFM_TOUR_PLANS_DET
                (ID, TOUR_ID, TOUR_DATE, ACT_TYPE, ROUTE_ID, EXP_BUSS, REMARK, DIVSN_ID, TOUR_HQ, JT_EMP_ID, TOWN, STATUS, CHG_ON, CHG_BY)
            VALUES
                (NULL, :plan_id, TO_DATE(:tour_date, 'YYYY-MM-DD'), :activity_type, :route_id, NULL, :remark, :division_id, :tour_hq, :joint_employee_id, :town, 'N', SYSDATE, :login_id)
            SQL, [
                'plan_id' => $planId,
                'tour_date' => $this->dataDate($date),
                'activity_type' => $activity,
                'route_id' => $routeId,
                'remark' => (string) ($input['remark'] ?? $input['remarks'] ?? ''),
                'division_id' => $divisionId,
                'tour_hq' => $hq,
                'joint_employee_id' => $joint,
                'town' => $town,
                'login_id' => $loginId,
            ]);
    }

    /** @return list<array<string, mixed>> */
    private function normalizeDays(string $month, array $days): array
    {
        $normalized = [];
        $seen = [];
        foreach ($days as $day) {
            if (!is_array($day)) throw new InvalidArgumentException('Each tour-plan day must be an object.');
            $date = $this->date((string) ($day['date'] ?? $day['tour_date'] ?? ''));
            if (substr($date, 0, 7) !== $month) throw new InvalidArgumentException('Every tour-plan date must be in the requested month.');
            $dateValue = new \DateTimeImmutable($date);
            if ((int) $dateValue->format('N') === 7) throw new InvalidArgumentException('Sunday is a non-working day and cannot have a tour plan.');
            if (isset($seen[$date])) throw new InvalidArgumentException('A tour-plan date may only be submitted once.');
            $seen[$date] = true;
            $day['date'] = $date;
            $normalized[] = $day;
        }
        return $normalized;
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
