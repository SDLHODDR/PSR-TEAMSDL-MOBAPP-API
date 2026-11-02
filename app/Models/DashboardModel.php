<?php

declare(strict_types=1);

namespace App\Models;

use SDL\Framework\Database;
use SDL\Framework\Model;

final class DashboardModel extends Model
{
    public function __construct(Database $db)
    {
        parent::__construct($db);
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

        $divisions = $this->fetchAll(<<<'SQL'
            SELECT d.DIVSN_ID
            FROM SFM_EMP_DIVISIONS d
            INNER JOIN SFM_DIVISIONS v ON v.DIVSN_ID = d.DIVSN_ID
            WHERE d.EMP_ID = :employee_id
              AND TRUNC(SYSDATE) BETWEEN d.EFFEC_FROM AND NVL(d.EFFEC_TO, DATE '3000-01-01')
            ORDER BY v.SEQ, d.DIVSN_ID
            SQL, ['employee_id' => $employeeId]);
        $divisionIds = array_map(static fn (array $row): int => (int) $row['DIVSN_ID'], $divisions);

        $hq = $this->fetchOne(<<<'SQL'
            SELECT g.GEO_ID, g.GEO_LABEL, g.GEO_DESC, g.GEO_LVL
            FROM (
                SELECT GEO_ID, GEO_LABEL, GEO_DESC, GEO_LVL, DIVSN_ID
                FROM TABLE(GEOEMP.LIST_DOWN(:employee_id, NULL, TRUNC(SYSDATE)))
            ) g
            WHERE g.DIVSN_ID IN (SELECT DIVSN_ID FROM SFM_EMP_DIVISIONS WHERE EMP_ID = :employee_id)
            FETCH FIRST 1 ROW ONLY
            SQL, ['employee_id' => $employeeId]);

        return [
            'non_reporting_days' => $this->findNonReportingDays($employeeId, (string) $employee['DOJ'], (string) $employee['EMP_CODE']),
            'open_requests' => $this->findOpenRequests($employeeId),
            'campaigns' => $this->findCampaigns($employeeId, $divisionIds),
            'sales' => $this->findSalesSummary($hq, $divisionIds),
        ];
    }

    private function findNonReportingDays(int $employeeId, string $doj, string $employeeCode): array
    {
        $start = $this->fetchOne(<<<'SQL'
            SELECT TO_CHAR(GREATEST(TO_DATE(:doj, 'dd-Mon-yyyy'), DATE '2025-10-01'), 'dd-Mon-yyyy') AS START_DATE
            FROM DUAL
            SQL, ['doj' => $doj]);
        $startDate = (string) ($start['START_DATE'] ?? '01-Oct-2025');

        $rows = $this->fetchAll(<<<'SQL'
            SELECT TO_CHAR(day_date, 'dd-Mon-yyyy') AS DAY_DATE
            FROM (
                SELECT TO_DATE(:start_date, 'dd-Mon-yyyy') - 1 + LEVEL AS day_date
                FROM DUAL
                CONNECT BY TO_DATE(:start_date, 'dd-Mon-yyyy') - 1 + LEVEL <= TRUNC(SYSDATE) - 1
                MINUS
                SELECT day_date
                FROM (
                    SELECT TRUNC(DAY_DATE) AS day_date
                    FROM SFM_DAY_PLANS
                    WHERE EMP_ID = :employee_id AND DAY_DATE >= TO_DATE(:start_date, 'dd-Mon-yyyy')
                      AND DAY_DATE < TRUNC(SYSDATE) AND STATUS IN ('A', 'T')
                    UNION
                    SELECT TRUNC(ASON_DATE)
                    FROM SFM_NONFLD_ACTIVITY
                    WHERE EMP_ID = :employee_id AND ASON_DATE >= TO_DATE(:start_date, 'dd-Mon-yyyy')
                      AND ASON_DATE < TRUNC(SYSDATE) AND ACT_TYPE <> 'F' AND STATUS IN ('A', 'T')
                    UNION
                    SELECT TRUNC(DAY_DATE)
                    FROM SFM_DAY_PLANS_OPEN
                    WHERE EMP_ID = :employee_id AND DAY_DATE >= TO_DATE(:start_date, 'dd-Mon-yyyy')
                      AND DAY_DATE < TRUNC(SYSDATE) AND STATUS IN ('A', 'T')
                )
            )
            ORDER BY day_date
            SQL, [
                'start_date' => $startDate,
                'employee_id' => $employeeId,
            ]);

        return array_map(static fn (array $row): string => (string) $row['DAY_DATE'], $rows);
    }

    private function findOpenRequests(int $employeeId): array
    {
        return $this->fetchAll(<<<'SQL'
            SELECT TO_CHAR(day_date, 'dd-Mon') AS DATE_LABEL,
                   TO_CHAR(day_date, 'dd-Mon-yyyy') AS DAY_DATE
            FROM (
                SELECT day_date
                FROM SFM_DAY_PLANS_OPEN
                WHERE EMP_ID = :employee_id AND STATUS = 'A'
                UNION
                SELECT o.day_date
                FROM SFM_DAY_PLANS d
                INNER JOIN SFM_DAY_PLANS_OPEN o
                    ON TRUNC(d.day_date) = TRUNC(o.day_date) AND d.emp_id = o.emp_id
                WHERE d.STATUS = 'N' AND d.EMP_ID = :employee_id AND o.STATUS = 'A'
                MINUS
                SELECT day_date
                FROM SFM_DAY_PLANS
                WHERE EMP_ID = :employee_id AND STATUS IN ('T', 'A')
                UNION
                SELECT ASON_DATE
                FROM SFM_NONFLD_ACTIVITY
                WHERE EMP_ID = :employee_id AND STATUS <> 'X'
            )
            WHERE day_date >= DATE '2025-10-01'
            ORDER BY day_date
            SQL, ['employee_id' => $employeeId]);
    }

    private function findCampaigns(int $employeeId, array $divisionIds): array
    {
        if ($divisionIds === []) {
            return [];
        }

        [$placeholders, $params] = $this->divisionParameters($divisionIds);
        $params['employee_id'] = $employeeId;

        return $this->fetchAll(<<<SQL
            SELECT s.STG_CODE, ROUND(SUM(NVL(c.POB_AMT, 0))) AS POB_AMT,
                   NVL(TO_CHAR(c.MNTH, 'DD-MON-YY'), TO_CHAR(LAST_DAY(TRUNC(SYSDATE)), 'DD-MON-YY')) AS MNTH
            FROM SFM_STRATEGY s
            INNER JOIN SFM_STG_DIVISIONS d ON d.STG_ID = s.ID
            LEFT JOIN SFM_CUST_STG_SUMMARY c
                ON c.STG_ID = s.ID AND c.EMP_ID = :employee_id
               AND c.MNTH = LAST_DAY(TRUNC(SYSDATE))
               AND c.CUST_ID IN (
                   SELECT CUST_ID FROM SFM_STG_CUSTOMER
                   WHERE EMP_ID = :employee_id AND STG_ID = s.ID
               )
            WHERE s.CAMPGN_FLAG = 'Y'
              AND d.DIVSN_ID IN ($placeholders)
              AND TRUNC(SYSDATE) BETWEEN TRUNC(s.STG_FROM) AND TRUNC(s.STG_TO)
            GROUP BY s.STG_CODE,
                     NVL(TO_CHAR(c.MNTH, 'DD-MON-YY'), TO_CHAR(LAST_DAY(TRUNC(SYSDATE)), 'DD-MON-YY'))
            ORDER BY s.STG_CODE, MNTH
            SQL, $params);
    }

    private function findSalesSummary(?array $hq, array $divisionIds): array
    {
        if ($hq === null || empty($hq['GEO_LABEL']) || $divisionIds === []) {
            return [
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
                  CASE WHEN TO_CHAR(SYSDATE, 'MON') = 'APR' THEN 0 ELSE -1 END)
            SQL, $params);

        $target = (float) ($row['YTM_TARGET'] ?? 0);
        $sale = (float) ($row['YTM_SALE'] ?? 0);
        $lastYearSale = (float) ($row['LAST_YEAR_YTM_SALE'] ?? 0);

        return [
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
