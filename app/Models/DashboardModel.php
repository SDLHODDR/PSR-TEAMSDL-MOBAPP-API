<?php

declare(strict_types=1);

namespace App\Models;

use SDL\Framework\Database;
use SDL\Framework\Model;

final class DashboardModel extends Model
{
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

        return [
            'non_reporting_days' => $this->findNonReportingDays($employeeId, (string) $employee['DOJ'], $fromDate, $toDate),
            'open_requests' => $this->findOpenRequests($employeeId, $fromDate, $toDate),
            'campaigns' => $this->findCampaigns($employeeId, $divisionIds, $toDate),
            'sales' => $this->findSalesSummary($hq, $divisionIds, $poolCount),
        ];
    }

    private function findNonReportingDays(int $employeeId, string $doj, string $fromDate, string $toDate): array
    {
        $rows = $this->fetchAll(<<<'SQL'
            SELECT TO_CHAR(day_date, 'dd-Mon-yyyy') AS DAY_DATE
            FROM (
                SELECT GREATEST(TO_DATE(:doj, 'dd-Mon-yyyy'), TO_DATE(:from_date, 'dd-Mon-yyyy')) - 1 + LEVEL AS day_date
                FROM DUAL
                CONNECT BY GREATEST(TO_DATE(:doj, 'dd-Mon-yyyy'), TO_DATE(:from_date, 'dd-Mon-yyyy')) - 1 + LEVEL < TO_DATE(:to_date, 'dd-Mon-yyyy') + 1
                MINUS
                SELECT day_date
                FROM (
                    SELECT TRUNC(DAY_DATE) AS day_date
                    FROM SFM_DAY_PLANS
                    WHERE EMP_ID = :employee_id AND DAY_DATE >= TO_DATE(:from_date, 'dd-Mon-yyyy')
                      AND DAY_DATE < TO_DATE(:to_date, 'dd-Mon-yyyy') + 1 AND STATUS IN ('A', 'T')
                    UNION
                    SELECT TRUNC(ASON_DATE)
                    FROM SFM_NONFLD_ACTIVITY
                    WHERE EMP_ID = :employee_id AND ASON_DATE >= TO_DATE(:from_date, 'dd-Mon-yyyy')
                      AND ASON_DATE < TO_DATE(:to_date, 'dd-Mon-yyyy') + 1 AND ACT_TYPE <> 'F' AND STATUS IN ('A', 'T')
                    UNION
                    SELECT TRUNC(DAY_DATE)
                    FROM SFM_DAY_PLANS_OPEN
                    WHERE EMP_ID = :employee_id AND DAY_DATE >= TO_DATE(:from_date, 'dd-Mon-yyyy')
                      AND DAY_DATE < TO_DATE(:to_date, 'dd-Mon-yyyy') + 1 AND STATUS IN ('A', 'T')
                )
            )
            ORDER BY day_date
            SQL, [
                'doj' => $doj,
                'from_date' => $fromDate,
                'to_date' => $toDate,
                'employee_id' => $employeeId,
            ]);

        return array_map(static fn (array $row): string => (string) $row['DAY_DATE'], $rows);
    }

    private function findOpenRequests(int $employeeId, string $fromDate, string $toDate): array
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
            WHERE day_date >= TO_DATE(:from_date, 'dd-Mon-yyyy')
              AND day_date < TO_DATE(:to_date, 'dd-Mon-yyyy') + 1
            ORDER BY day_date
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
