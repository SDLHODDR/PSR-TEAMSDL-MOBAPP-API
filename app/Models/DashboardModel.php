<?php

declare(strict_types=1);

namespace App\Models;

use SDL\Framework\Database;
use SDL\Framework\Model;

final class DashboardModel extends Model
{
    private const FRANCHISE_HOLIDAY_GROUPS = [
        'FR004' => 'Q1006',
        'FR007' => 'Q1002',
        'FR006' => 'Q1002',
        'FR001' => 'Q1003',
        'FR002' => 'Q1003',
    ];

    private EmployeeAuthModel $employeeAuthModel;

    public function __construct(Database $db)
    {
        parent::__construct($db);
        $this->employeeAuthModel = new EmployeeAuthModel($db);
    }

    public function findDashboardByEmployeeId(int $employeeId): array
    {
        $employee = $this->fetchOne(<<<'SQL'
            SELECT EMP_CODE, TO_CHAR(DOJ, 'dd-Mon-yyyy') AS DOJ
            FROM SFM_EMPLOYEE_INFO
            WHERE ID = :employee_id AND STATUS = 'A'
            SQL, ['employee_id' => $employeeId]);

        if ($employee === null) {
            return [];
        }

        $financialYear = $this->fetchOne(<<<'SQL'
            WITH FINYEAR AS (
                SELECT FROM_DT, TO_DATE,
                       ROW_NUMBER() OVER (
                           ORDER BY CASE
                               WHEN TRUNC(SYSDATE) BETWEEN FROM_DT AND TO_DATE THEN 1
                               ELSE 2
                           END,
                           FROM_DT DESC
                       ) AS RN
                FROM SFM_FINYEAR
            )
            SELECT TO_CHAR(FROM_DT, 'DD-Mon-YYYY') AS FROMDATE,
                   TO_CHAR(TO_DATE, 'DD-Mon-YYYY') AS TODATE
            FROM FINYEAR
            WHERE RN = 1
            SQL);

        if ($financialYear === null || empty($financialYear['FROMDATE']) || empty($financialYear['TODATE'])) {
            throw new \RuntimeException('The active financial year was not found.');
        }

        $fromDate = (string) $financialYear['FROMDATE'];
        $toDate = (string) $financialYear['TODATE'];

        $divisionIds = $this->employeeAuthModel->getEmpDivisions($employeeId);

        $hq = null;
        if ($divisionIds !== []) {
            [$divisionPlaceholders, $divisionParams] = $this->divisionParameters($divisionIds);
            $divisionParams['employee_id'] = $employeeId;

            $hq = $this->fetchOne(<<<SQL
                SELECT GEO_ID, GEO_LABEL, GEO_DESC, GEO_LVL
                FROM (
                    SELECT DISTINCT g.GEO_ID, g.GEO_LABEL, g.GEO_DESC, g.GEO_LVL
                    FROM TABLE(GEOEMP.LIST_DOWN(:employee_id, NULL, TRUNC(SYSDATE))) g
                    INNER JOIN SFM_EMPLOYEE_INFO e ON e.ID = g.EMP_ID
                    WHERE g.DIVSN_ID IN ($divisionPlaceholders)
                      AND e.ID = :employee_id
                )
                SQL, $divisionParams);
        }

        $poolCount = 1;
        if ($hq !== null && (int) ($hq['GEO_LVL'] ?? 0) === 100 && $divisionIds !== []) {
            $pool = $this->fetchOne(<<<'SQL'
                SELECT COUNT(1) AS POOL_COUNT
                FROM NEW_GEO_MAPPING
                WHERE GEO_LABEL = get_empid_hqlbl(:employee_id, LAST_DAY(SYSDATE))
                  AND DIVSN_ID = :division_id
                  AND LAST_DAY(SYSDATE) BETWEEN EFFEC_FROM
                      AND NVL(EFFEC_TO, LAST_DAY(SYSDATE))
                SQL, [
                    'employee_id' => $employeeId,
                    'division_id' => $divisionIds[0],
                ]);
            $poolCount = max(1, (int) ($pool['POOL_COUNT'] ?? 0));
        }

        $nonReportingDays = $this->findNonReportingDays(
                $employeeId,
                (string) $employee['EMP_CODE'],
                (string) $employee['DOJ'],
                $fromDate,
                $toDate
            );
        $openRequests = $this->findOpenRequests($employeeId, $fromDate, $toDate);
        $rejectedRequests = $this->findRejectedRequests($employeeId, $fromDate, $toDate);

        return [
            'non_reporting_days' => $nonReportingDays,
            'open_requests' => $openRequests,
            'reporting_status' => [
                ...array_map(
                    static fn (string $date): array => [
                        'day_date' => $date,
                        'status' => 'non_reported',
                    ],
                    $nonReportingDays
                ),
                ...array_map(
                    static fn (array $row): array => [
                        'day_date' => $row['DAY_DATE'],
                        'status' => 'delayed',
                    ],
                    $openRequests
                ),
                ...array_map(
                    static fn (array $row): array => [
                        'day_date' => $row['DAY_DATE'],
                        'status' => 'rejected',
                    ],
                    $rejectedRequests
                ),
            ],
            'campaigns' => $this->findCampaigns($employeeId, $divisionIds, $toDate),
            'sales' => $this->findSalesSummary($hq, $divisionIds, $poolCount),
        ];
    }

    private function findNonReportingDays(
        int $employeeId,
        string $employeeCode,
        string $doj,
        string $fromDate,
        string $toDate
    ): array {
        $rows = $this->fetchAll(<<<'SQL'
            WITH calendar_days AS (
                SELECT GREATEST(
                           TO_DATE(:doj, 'dd-Mon-yyyy'),
                           TO_DATE(:from_date, 'dd-Mon-yyyy')
                       ) - 1 + LEVEL AS report_day
                FROM DUAL
                CONNECT BY GREATEST(
                               TO_DATE(:doj, 'dd-Mon-yyyy'),
                               TO_DATE(:from_date, 'dd-Mon-yyyy')
                           ) - 1 + LEVEL
                           <= LEAST(TO_DATE(:to_date, 'dd-Mon-yyyy'), TRUNC(SYSDATE) - 1)
            ),
            reported_days AS (
                SELECT TRUNC(DAY_DATE) AS report_day
                FROM SFM_DAY_PLANS
                WHERE EMP_ID = :employee_id
                  AND DAY_DATE >= GREATEST(
                      TO_DATE(:doj, 'dd-Mon-yyyy'),
                      TO_DATE(:from_date, 'dd-Mon-yyyy')
                  )
                  AND DAY_DATE < LEAST(TO_DATE(:to_date, 'dd-Mon-yyyy') + 1, TRUNC(SYSDATE))
                  AND STATUS IN ('A', 'T')
                UNION
                SELECT TRUNC(ASON_DATE)
                FROM SFM_NONFLD_ACTIVITY
                WHERE EMP_ID = :employee_id
                  AND ASON_DATE >= GREATEST(
                      TO_DATE(:doj, 'dd-Mon-yyyy'),
                      TO_DATE(:from_date, 'dd-Mon-yyyy')
                  )
                  AND ASON_DATE < LEAST(TO_DATE(:to_date, 'dd-Mon-yyyy') + 1, TRUNC(SYSDATE))
                  AND ACT_TYPE <> 'F'
                  AND STATUS IN ('A', 'T')
                UNION
                SELECT TRUNC(a.DAY_DATE)
                FROM SFM_DAY_PLANS_OPEN a
                INNER JOIN SFM_USER_TASKS t
                    ON t.TRAN_ID = a.ID AND t.EMP_ID = a.EMP_ID
                WHERE a.EMP_ID = :employee_id
                  AND a.DAY_DATE >= GREATEST(
                      TO_DATE(:doj, 'dd-Mon-yyyy'),
                      TO_DATE(:from_date, 'dd-Mon-yyyy')
                  )
                  AND a.DAY_DATE < LEAST(TO_DATE(:to_date, 'dd-Mon-yyyy') + 1, TRUNC(SYSDATE))
                  AND a.STATUS IN ('A', 'T')
                UNION
                SELECT TRUNC(d.DAY_DATE)
                FROM SFM_DAY_PLANS d
                WHERE d.EMP_ID = :employee_id
                  AND d.STATUS = 'N'
                  AND EXISTS (
                      SELECT 1
                      FROM SFM_USER_TASKS rejected_task
                      WHERE rejected_task.ID = (
                          SELECT MAX(latest_task.ID)
                          FROM SFM_USER_TASKS latest_task
                          WHERE latest_task.TRAN_ID = d.ID
                            AND latest_task.TASK_GROUP = 'DayPlanAuth'
                      )
                        AND rejected_task.STATUS = 'R'
                  )
                UNION
                SELECT TRUNC(HOL_DATE)
                FROM EPPLIVE.BCS_HOLIDAYS
                WHERE HOL_GRP = (
                    SELECT HOL_TBLNO
                    FROM EPPLIVE.BCS_EMPLOYEE
                    WHERE EMP_CODE = :employee_code
                )
                  AND HOL_DATE >= GREATEST(
                      TO_DATE(:doj, 'dd-Mon-yyyy'),
                      TO_DATE(:from_date, 'dd-Mon-yyyy')
                  )
                  AND HOL_DATE < LEAST(TO_DATE(:to_date, 'dd-Mon-yyyy') + 1, TRUNC(SYSDATE))
                UNION
                SELECT TRUNC(HOL_DATE)
                FROM EPPLIVE.BCS_HOLIDAYS
                WHERE HOL_GRP = :franchise_holiday_group
                  AND HOL_DATE >= GREATEST(
                      TO_DATE(:doj, 'dd-Mon-yyyy'),
                      TO_DATE(:from_date, 'dd-Mon-yyyy')
                  )
                  AND HOL_DATE < LEAST(TO_DATE(:to_date, 'dd-Mon-yyyy') + 1, TRUNC(SYSDATE))
                UNION
                SELECT TRUNC(l.LVE_DATE_FR + n.NUM)
                FROM EPPLIVE.BCS_EMP_LEAVES_TEMP l
                CROSS JOIN (
                    SELECT LEVEL - 1 AS NUM
                    FROM DUAL
                    CONNECT BY LEVEL <= 100
                ) n
                WHERE l.EMP_CODE = :employee_code
                  AND l.STATUS IN ('A', 'T', 'X')
                  AND l.LVE_DATE_FR + n.NUM <= l.LVE_DATE_TO
                  AND l.LVE_DATE_FR + n.NUM >= GREATEST(
                      TO_DATE(:doj, 'dd-Mon-yyyy'),
                      TO_DATE(:from_date, 'dd-Mon-yyyy')
                  )
                  AND l.LVE_DATE_FR + n.NUM < LEAST(
                      TO_DATE(:to_date, 'dd-Mon-yyyy') + 1,
                      TRUNC(SYSDATE)
                  )
                UNION
                SELECT TRUNC(l.LVE_DATE_FR + n.NUM)
                FROM EPPLIVE.BCS_EMP_LEAVES l
                CROSS JOIN (
                    SELECT LEVEL - 1 AS NUM
                    FROM DUAL
                    CONNECT BY LEVEL <= 100
                ) n
                WHERE l.EMP_CODE = :employee_code
                  AND l.STATUS IN ('A', 'T', 'X')
                  AND l.LVE_DATE_FR + n.NUM <= l.LVE_DATE_TO
                  AND l.LVE_DATE_FR + n.NUM >= GREATEST(
                      TO_DATE(:doj, 'dd-Mon-yyyy'),
                      TO_DATE(:from_date, 'dd-Mon-yyyy')
                  )
                  AND l.LVE_DATE_FR + n.NUM < LEAST(
                      TO_DATE(:to_date, 'dd-Mon-yyyy') + 1,
                      TRUNC(SYSDATE)
                  )
            )
            SELECT TO_CHAR(missing.report_day, 'dd-Mon-yyyy') AS DAY_DATE
            FROM (
                SELECT report_day FROM calendar_days
                MINUS
                SELECT report_day FROM reported_days
            ) missing
            ORDER BY missing.report_day
            SQL, [
                'doj' => $doj,
                'from_date' => $fromDate,
                'to_date' => $toDate,
                'employee_id' => $employeeId,
                'employee_code' => $employeeCode,
                'franchise_holiday_group' => self::FRANCHISE_HOLIDAY_GROUPS[strtoupper($employeeCode)] ?? null,
            ]);

        return array_map(static fn (array $row): string => (string) $row['DAY_DATE'], $rows);
    }

    private function findOpenRequests(int $employeeId, string $fromDate, string $toDate): array
    {
        return $this->fetchAll(<<<'SQL'
            SELECT TO_CHAR(pending_days.report_day, 'dd-Mon') AS DATE_LABEL,
                   'delayed' AS STATUS,
                   TO_CHAR(pending_days.report_day, 'dd-Mon-yyyy') AS DAY_DATE
            FROM (
                SELECT candidate_days.report_day
                FROM (
                    SELECT TRUNC(day_date) AS report_day
                    FROM SFM_DAY_PLANS_OPEN
                    WHERE EMP_ID = :employee_id AND STATUS = 'A'
                    UNION
                    SELECT TRUNC(o.day_date)
                    FROM SFM_DAY_PLANS d
                    INNER JOIN SFM_DAY_PLANS_OPEN o
                        ON TRUNC(d.day_date) = TRUNC(o.day_date) AND d.emp_id = o.emp_id
                    WHERE d.STATUS = 'N' AND d.EMP_ID = :employee_id AND o.STATUS = 'A'
                ) candidate_days
                MINUS
                SELECT completed_days.report_day
                FROM (
                    SELECT TRUNC(day_date) AS report_day
                    FROM SFM_DAY_PLANS
                    WHERE EMP_ID = :employee_id AND STATUS IN ('T', 'A')
                    UNION
                    SELECT TRUNC(ASON_DATE)
                    FROM SFM_NONFLD_ACTIVITY
                    WHERE EMP_ID = :employee_id AND STATUS <> 'X'
                ) completed_days
            ) pending_days
            WHERE pending_days.report_day >= TO_DATE(:from_date, 'dd-Mon-yyyy')
              AND pending_days.report_day <= TO_DATE(:to_date, 'dd-Mon-yyyy')
              AND pending_days.report_day <= TRUNC(SYSDATE)
            ORDER BY pending_days.report_day
            SQL, [
                'employee_id' => $employeeId,
                'from_date' => $fromDate,
                'to_date' => $toDate,
            ]);
    }

    private function findRejectedRequests(int $employeeId, string $fromDate, string $toDate): array
    {
        return $this->fetchAll(<<<'SQL'
            SELECT x.ID, TO_CHAR(x.DAY_DATE, 'dd-Mon') AS DATE_LABEL,
                   TO_CHAR(x.DAY_DATE, 'dd-Mon-yyyy') AS DAY_DATE
            FROM (
                SELECT d.ID, d.DAY_DATE, MAX(t.ID) AS TASK_ID
                FROM SFM_DAY_PLANS d
                INNER JOIN SFM_USER_TASKS t
                    ON t.TRAN_ID = d.ID
                   AND t.EMP_ID = d.EMP_ID
                WHERE t.TASK_GROUP = 'DayPlanAuth'
                  AND d.EMP_ID = :employee_id
                  AND d.STATUS = 'N'
                  AND d.DAY_DATE >= TO_DATE(:from_date, 'dd-Mon-yyyy')
                  AND d.DAY_DATE <= TO_DATE(:to_date, 'dd-Mon-yyyy')
                  AND d.DAY_DATE <= TRUNC(SYSDATE)
                GROUP BY d.ID, d.DAY_DATE
            ) x
            INNER JOIN SFM_USER_TASKS latest_task
                ON latest_task.ID = x.TASK_ID
            WHERE latest_task.STATUS = 'R'
              AND NOT EXISTS (
                  SELECT 1
                  FROM SFM_MONTHLY_EXPENSE expense
                  WHERE expense.EMP_ID = :employee_id
                    AND NVL(expense.STATUS, 'N') = 'A'
                    AND x.DAY_DATE BETWEEN
                        TO_DATE(TO_CHAR(expense.ASON_DATE, 'Mon-yyyy'), 'Mon-yyyy')
                        AND LAST_DAY(expense.ASON_DATE)
              )
            ORDER BY x.DAY_DATE DESC
            SQL, [
                'employee_id' => $employeeId,
                'from_date' => $fromDate,
                'to_date' => $toDate,
            ]);
    }

    private function findCampaigns(int $employeeId, array $divisionIds, string $toDate): array
    {
        if ($divisionIds === []) {
            return [];
        }

        [$placeholders, $params] = $this->divisionParameters($divisionIds);
        $params['employee_id'] = $employeeId;
        $params['to_date'] = $toDate;

        return $this->fetchAll(<<<SQL
            SELECT s.STG_CODE, ROUND(SUM(NVL(c.POB_AMT, 0))) AS POB_AMT,
                   NVL(TO_CHAR(c.MNTH, 'DD-MON-YY'), TO_CHAR(LAST_DAY(TO_DATE(:to_date, 'DD-Mon-YYYY')), 'DD-MON-YY')) AS MNTH
            FROM SFM_STRATEGY s
            INNER JOIN SFM_STG_DIVISIONS d ON d.STG_ID = s.ID
            LEFT JOIN SFM_CUST_STG_SUMMARY c
                ON c.STG_ID = s.ID AND c.EMP_ID = :employee_id
               AND c.MNTH = LAST_DAY(TO_DATE(:to_date, 'DD-Mon-YYYY'))
               AND c.CUST_ID IN (
                   SELECT CUST_ID FROM SFM_STG_CUSTOMER
                   WHERE EMP_ID = :employee_id AND STG_ID = s.ID
               )
            WHERE s.CAMPGN_FLAG = 'Y'
              AND d.DIVSN_ID IN ($placeholders)
              AND TO_DATE(:to_date, 'DD-Mon-YYYY') BETWEEN TRUNC(s.STG_FROM) AND TRUNC(s.STG_TO)
            GROUP BY s.STG_CODE,
                     NVL(TO_CHAR(c.MNTH, 'DD-MON-YY'), TO_CHAR(LAST_DAY(TO_DATE(:to_date, 'DD-Mon-YYYY')), 'DD-MON-YY'))
            ORDER BY s.STG_CODE, MNTH
            SQL, $params);
    }

    private function findSalesSummary(?array $hq, array $divisionIds, int $poolCount): array
    {
        if ($hq === null || empty($hq['GEO_LABEL']) || $divisionIds === []) {
            return [
                'cy_target' => '0.00', 'cy_sale' => '0.00', 'ly_sale' => '0.00',
                'ytm_target' => 0, 'ytm_sale' => 0, 'last_year_ytm_sale' => 0,
                'ytm_achievement' => 0, 'ytm_growth' => 0,
            ];
        }

        [$placeholders, $params] = $this->divisionParameters($divisionIds);
        $params['geo_label'] = $hq['GEO_LABEL'];
        
        $row = $this->fetchOne(<<<SQL
            SELECT NVL(SUM(TG_CUM_CTP_VALUE), 0) AS YTM_TARGET,
                   NVL(SUM(CY_CUM_CTP_VALUE), 0) AS YTM_SALE,
                   NVL(SUM(LY1_CUM_CTP_VALUE), 0) AS LAST_YEAR_YTM_SALE
            FROM NEW_GEO_TGTSALES
            WHERE GEO_LABEL = :geo_label
              AND DIVSN_ID IN ($placeholders)
              AND MNTH = ADD_MONTHS(TRUNC(SYSDATE, 'MM'),
                  CASE WHEN EXTRACT(MONTH FROM SYSDATE) = 4 THEN 0 ELSE -1 END)
            SQL, $params);

        $target = (float) ($row['YTM_TARGET'] ?? 0) / $poolCount;
        $sale = (float) ($row['YTM_SALE'] ?? 0) / $poolCount;
        $lastYearSale = (float) ($row['LAST_YEAR_YTM_SALE'] ?? 0) / $poolCount;

        return [
            'cy_target' => number_format($target / 100000, 2, '.', ''),
            'cy_sale' => number_format($sale / 100000, 2, '.', ''),
            'ly_sale' => number_format($lastYearSale / 100000, 2, '.', ''),
            'ytm_target' => $target,
            'ytm_sale' => $sale,
            'last_year_ytm_sale' => $lastYearSale,
            'ytm_achievement' => $target == 0.0 ? 0 : round(($sale * 100) / $target, 2),
            'ytm_growth' => $lastYearSale == 0.0 ? 0 : round((($sale * 100) / $lastYearSale) - 100, 2),
        ];
    }

    /** @return array{0:string,1:array<string,int>} */
    private function divisionParameters(array $divisionIds): array
    {
        $params = [];
        $placeholders = [];
        foreach (array_values($divisionIds) as $index => $divisionId) {
            $key = 'division_' . $index;
            $placeholders[] = ':' . $key;
            $params[$key] = $divisionId;
        }
        return [implode(', ', $placeholders), $params];
    }
}
