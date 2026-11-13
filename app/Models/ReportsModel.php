<?php

declare(strict_types=1);

namespace App\Models;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use RuntimeException;
use SDL\Framework\Database;
use SDL\Framework\Model;

final class ReportsModel extends Model
{
    public function __construct(Database $db)
    {
        parent::__construct($db);
    }

    public function monthly(int $employeeId, string $month): array
    {
        $month = $this->month($month);
        $todayRow = $this->fetchOne(
            "SELECT TO_CHAR(TRUNC(SYSDATE), 'YYYY-MM-DD') AS TODAY FROM DUAL"
        );
        if ($todayRow === null || empty($todayRow['TODAY'])) {
            throw new RuntimeException('The database date could not be read.');
        }
        $today = new DateTimeImmutable((string) $todayRow['TODAY']);
        $firstDate = new DateTimeImmutable($month . '-01');
        $lastDate = $firstDate->modify('last day of this month');
        $yesterday = $today->modify('-1 day');
        if ($lastDate > $yesterday) {
            $lastDate = $yesterday;
        }

        $days = [];
        if ($firstDate <= $lastDate) {
            for ($date = $firstDate; $date <= $lastDate; $date = $date->modify('+1 day')) {
                $key = $date->format('Y-m-d');
                $days[$key] = [
                    'date' => $key,
                    'day' => $date->format('d'),
                    'kind' => $date->format('N') === '7' ? 'holiday' : 'noReport',
                    'summary' => $date->format('N') === '7' ? 'Sunday' : 'No report',
                    'route_id' => null,
                    'pob' => 0.0,
                    'sob' => 0.0,
                    'has_details' => false,
                ];
            }
        }

        if ($days === []) {
            return ['month' => $month, 'label' => $firstDate->format('M-Y'), 'days' => []];
        }

        $startDate = $firstDate->format('Y-m-d');
        $endDateExclusive = $lastDate->modify('+1 day')->format('Y-m-d');
        $params = [
            'employee_id' => $employeeId,
            'from_date' => $startDate,
            'to_date' => $endDateExclusive,
        ];

        $plans = $this->fetchAll(<<<'SQL'
            SELECT p.ID AS PLAN_ID,
                   TO_CHAR(p.DAY_DATE, 'YYYY-MM-DD') AS PLAN_DATE,
                   p.STATUS AS PLAN_STATUS,
                   r.ROUTE_ID,
                   r.TOWN,
                   NVL(t.POB, 0) AS POB
            FROM SFM_DAY_PLANS p
            LEFT JOIN (
                SELECT DAYPLAN_ID,
                       MIN(ROUTE_ID) KEEP (DENSE_RANK FIRST ORDER BY ID) AS ROUTE_ID,
                       SUM(NVL(TOTAL_POB, 0)) AS POB
                FROM SFM_DAY_PLANS_DETAILS
                GROUP BY DAYPLAN_ID
            ) d ON d.DAYPLAN_ID = p.ID
            LEFT JOIN (
                SELECT DAYPLAN_ID,
                       ROUTE_ID,
                       INITCAP(MAX(TOWN_DESC)) AS TOWN
                FROM (
                    SELECT x.DAYPLAN_ID, x.ROUTE_ID, h.TOWN_DESC,
                           ROW_NUMBER() OVER (
                               PARTITION BY x.DAYPLAN_ID, x.ROUTE_ID
                               ORDER BY x.ID DESC
                           ) AS RN
                    FROM SFM_DAY_PLANS_DETAILS x
                    INNER JOIN SFM_GEO_HQ_TOWNS h ON h.TOWN_ID = x.TOWN_ID
                )
                WHERE RN = 1
                GROUP BY DAYPLAN_ID, ROUTE_ID
            ) r ON r.DAYPLAN_ID = p.ID AND r.ROUTE_ID = d.ROUTE_ID
            LEFT JOIN (
                SELECT DAYPLAN_ID, SUM(NVL(TOTAL_POB, 0)) AS POB
                FROM SFM_DAY_PLANS_DETAILS
                GROUP BY DAYPLAN_ID
            ) t ON t.DAYPLAN_ID = p.ID
            WHERE p.EMP_ID = :employee_id
              AND p.DAY_DATE >= TO_DATE(:from_date, 'YYYY-MM-DD')
              AND p.DAY_DATE < TO_DATE(:to_date, 'YYYY-MM-DD')
            ORDER BY p.DAY_DATE
            SQL, $params);

        foreach ($plans as $plan) {
            $key = (string) $plan['PLAN_DATE'];
            if (!isset($days[$key])) {
                continue;
            }
            $routeId = $plan['ROUTE_ID'] === null ? null : (string) $plan['ROUTE_ID'];
            $town = trim((string) ($plan['TOWN'] ?? ''));
            $days[$key] = [
                'date' => $key,
                'day' => substr($key, 8, 2),
                'kind' => 'route',
                'summary' => $town !== '' ? $town : ($routeId !== null ? 'Route ' . $routeId : 'Day plan'),
                'route_id' => $routeId,
                'pob' => (float) $plan['POB'],
                'sob' => 0.0,
                'has_details' => true,
            ];
        }

        $activities = $this->fetchAll(<<<'SQL'
            SELECT TO_CHAR(a.ASON_DATE, 'YYYY-MM-DD') AS ACTIVITY_DATE,
                   t.ACT_DESC AS ACTIVITY
            FROM SFM_NONFLD_ACTIVITY a
            INNER JOIN SFM_ACTIVITY_TYPE t ON t.ACT_TYPE = a.ACT_TYPE
            WHERE a.EMP_ID = :employee_id
              AND a.ASON_DATE >= TO_DATE(:from_date, 'YYYY-MM-DD')
              AND a.ASON_DATE < TO_DATE(:to_date, 'YYYY-MM-DD')
              AND a.STATUS = 'A'
              AND a.ACT_TYPE <> 'F'
            ORDER BY a.ASON_DATE, a.ID DESC
            SQL, $params);

        foreach ($activities as $activity) {
            $key = (string) $activity['ACTIVITY_DATE'];
            if (!isset($days[$key])) {
                continue;
            }
            $days[$key] = [
                'date' => $key,
                'day' => substr($key, 8, 2),
                'kind' => 'activity',
                'summary' => (string) $activity['ACTIVITY'],
                'route_id' => null,
                'pob' => 0.0,
                'sob' => 0.0,
                'has_details' => false,
            ];
        }

        $employee = $this->fetchOne(
            'SELECT EMP_CODE FROM SFM_EMPLOYEE_INFO WHERE ID = :employee_id',
            ['employee_id' => $employeeId]
        );
        if ($employee !== null) {
            $employeeCode = (string) $employee['EMP_CODE'];
            $holidays = $this->fetchAll(<<<'SQL'
                SELECT TO_CHAR(h.HOL_DATE, 'YYYY-MM-DD') AS HOLIDAY_DATE,
                       INITCAP(h.DESCR) AS DESCRIPTION
                FROM EPPLIVE.BCS_HOLIDAYS h
                WHERE h.HOL_GRP = (
                    SELECT e.HOL_TBLNO
                    FROM EPPLIVE.BCS_EMPLOYEE e
                    WHERE e.EMP_CODE = :employee_code
                )
                  AND h.HOL_DATE >= TO_DATE(:from_date, 'YYYY-MM-DD')
                  AND h.HOL_DATE < TO_DATE(:to_date, 'YYYY-MM-DD')
                SQL, [
                    'employee_code' => $employeeCode,
                    'from_date' => $startDate,
                    'to_date' => $endDateExclusive,
                ]);
            foreach ($holidays as $holiday) {
                $key = (string) $holiday['HOLIDAY_DATE'];
                if (!isset($days[$key])) {
                    continue;
                }
                $days[$key] = [
                    'date' => $key,
                    'day' => substr($key, 8, 2),
                    'kind' => 'holiday',
                    'summary' => (string) $holiday['DESCRIPTION'],
                    'route_id' => null,
                    'pob' => 0.0,
                    'sob' => 0.0,
                    'has_details' => false,
                ];
            }

            $leaves = $this->fetchAll(<<<'SQL'
                SELECT TO_CHAR(LVE_DATE_FR, 'YYYY-MM-DD') AS LEAVE_DATE,
                       LVE_CODE
                FROM EPPLIVE.BCS_EMP_LEAVES
                WHERE EMP_CODE = :employee_code
                  AND STATUS IN ('A', 'T')
                  AND LVE_DATE_FR >= TO_DATE(:from_date, 'YYYY-MM-DD')
                  AND LVE_DATE_FR < TO_DATE(:to_date, 'YYYY-MM-DD')
                SQL, [
                    'employee_code' => $employeeCode,
                    'from_date' => $startDate,
                    'to_date' => $endDateExclusive,
                ]);
            foreach ($leaves as $leave) {
                $key = (string) $leave['LEAVE_DATE'];
                if (!isset($days[$key])) {
                    continue;
                }
                $days[$key] = [
                    'date' => $key,
                    'day' => substr($key, 8, 2),
                    'kind' => 'holiday',
                    'summary' => (string) $leave['LVE_CODE'],
                    'route_id' => null,
                    'pob' => 0.0,
                    'sob' => 0.0,
                    'has_details' => false,
                ];
            }
        }

        $openRequests = $this->fetchAll(<<<'SQL'
            SELECT TO_CHAR(day_date, 'YYYY-MM-DD') AS OPEN_DATE,
                   CASE STATUS
                       WHEN 'N' THEN 'Request Pending'
                       WHEN 'X' THEN 'Request Rejected'
                       ELSE 'Request Authorized'
                   END AS REQUEST_STATUS
            FROM (
                SELECT o.*,
                       ROW_NUMBER() OVER (
                           PARTITION BY TRUNC(o.DAY_DATE)
                           ORDER BY o.ID DESC
                       ) AS RN
                FROM SFM_DAY_PLANS_OPEN o
                WHERE o.EMP_ID = :employee_id
                  AND o.DAY_DATE >= TO_DATE(:from_date, 'YYYY-MM-DD')
                  AND o.DAY_DATE < TO_DATE(:to_date, 'YYYY-MM-DD')
            )
            WHERE RN = 1
            ORDER BY day_date
            SQL, $params);

        foreach ($openRequests as $request) {
            $key = (string) $request['OPEN_DATE'];
            if (!isset($days[$key]) || $days[$key]['kind'] === 'route' || $days[$key]['kind'] === 'activity') {
                continue;
            }
            $days[$key] = [
                'date' => $key,
                'day' => substr($key, 8, 2),
                'kind' => 'openRequest',
                'summary' => (string) $request['REQUEST_STATUS'],
                'route_id' => null,
                'pob' => 0.0,
                'sob' => 0.0,
                'has_details' => false,
            ];
        }

        return [
            'month' => $month,
            'label' => $firstDate->format('M-Y'),
            'days' => array_values($days),
        ];
    }

    public function daily(int $employeeId, string $date): ?array
    {
        $date = $this->date($date);
        $plan = $this->fetchOne(<<<'SQL'
            SELECT PLAN_ID, PLAN_DATE
            FROM (
                SELECT ID AS PLAN_ID,
                       TO_CHAR(DAY_DATE, 'YYYY-MM-DD') AS PLAN_DATE
                FROM SFM_DAY_PLANS
                WHERE EMP_ID = :employee_id
                  AND DAY_DATE >= TO_DATE(:report_date, 'YYYY-MM-DD')
                  AND DAY_DATE < TO_DATE(:report_date, 'YYYY-MM-DD') + 1
                ORDER BY ID DESC
            )
            WHERE ROWNUM = 1
            SQL, ['employee_id' => $employeeId, 'report_date' => $date]);
        if ($plan === null) {
            return null;
        }

        $planId = (int) $plan['PLAN_ID'];
        $listed = $this->fetchAll(<<<'SQL'
            SELECT d.ID AS DETAIL_ID,
                   d.ROUTE_ID,
                   c.CUST_NAME AS CUSTOMER,
                   NVL(d.TOTAL_POB, 0) AS POB
            FROM SFM_DAY_PLANS_DETAILS d
            INNER JOIN SFM_CUSTOMER_INFO c ON c.ID = d.CUST_ID
            WHERE d.DAYPLAN_ID = :plan_id
            ORDER BY d.ID
            SQL, ['plan_id' => $planId]);

        $unlisted = $this->fetchAll(<<<'SQL'
            SELECT ID AS DETAIL_ID, CUST_NAME AS CUSTOMER,
                   NVL(TOTAL_POB, 0) AS POB
            FROM SFM_DAY_PLANS_UNLISTED
            WHERE DAYPLAN_ID = :plan_id
            ORDER BY ID
            SQL, ['plan_id' => $planId]);

        $details = array_map(static fn (array $row): array => [
            'customer' => ucwords(strtolower((string) $row['CUSTOMER'])),
            'pob' => (float) $row['POB'],
            'route_id' => $row['ROUTE_ID'] === null ? null : (string) $row['ROUTE_ID'],
            'unlisted' => false,
        ], $listed);
        foreach ($unlisted as $row) {
            $details[] = [
                'customer' => (string) $row['CUSTOMER'],
                'pob' => (float) $row['POB'],
                'route_id' => null,
                'unlisted' => true,
            ];
        }

        return [
            'date' => $date,
            'details' => $details,
        ];
    }

    private function month(string $value): string
    {
        if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $value)) {
            throw new InvalidArgumentException('month must use YYYY-MM format.');
        }
        return $value;
    }

    private function date(string $value): string
    {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone('UTC'));
        if ($parsed === false || $parsed->format('Y-m-d') !== $value) {
            throw new InvalidArgumentException('date must use YYYY-MM-DD format.');
        }
        return $value;
    }
}
