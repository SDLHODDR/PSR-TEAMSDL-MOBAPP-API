<?php

declare(strict_types=1);

namespace App\Models;

use DateTimeImmutable;
use InvalidArgumentException;
use RuntimeException;
use SDL\Framework\Database;
use SDL\Framework\Model;

final class PobSobModel extends Model
{
    public function __construct(
        Database $db,
        private readonly ?EmployeeAuthModel $employeeAuthModel = null
    ) {
        parent::__construct($db);
    }

    public function ordersByMonth(int $employeeId, string $month): array
    {
        $month = $this->month($month);
        $firstDate = new DateTimeImmutable($month . '-01');
        $nextMonth = $firstDate->modify('+1 month');
        $divisionModel = $this->employeeAuthModel ?? new EmployeeAuthModel($this->db);
        $divisions = $divisionModel->getEmpDivisions($employeeId);
        if ($divisions === []) {
            throw new RuntimeException('No active division is configured for this employee.');
        }

        $geo = $this->fetchOne(<<<'SQL'
            SELECT GEO_ID
            FROM (
                SELECT g.GEO_ID
                FROM TABLE(
                    GEOEMP.LIST_DOWN(
                        :employee_id, NULL, TRUNC(ADD_MONTHS(SYSDATE, -24))
                    )
                ) g
                WHERE g.EMP_ID = :employee_id
            )
            WHERE ROWNUM = 1
            SQL, ['employee_id' => $employeeId]);
        if ($geo === null || empty($geo['GEO_ID'])) {
            throw new RuntimeException('No active geography is configured for this employee.');
        }

        $rows = $this->fetchAll(<<<'SQL'
            WITH source_orders AS (
                SELECT a.CUST_ID, b.ORDER_TO AS ORDER_TO_ID,
                       a.DAYPLAN_ID AS PLAN_ID,
                       SUM(a.POBAMT) AS AMOUNT, a.DAY_DATE,
                       b.CONFIRM_OTP, p.STATUS AS PLAN_STATUS,
                       b.OTP_NO, b.VERIFY_ON, 'CP' AS SOURCE_TABLE
                FROM SFM_DAY_PLANS_CUSTPOB a
                INNER JOIN SFM_DAY_PLANS_DETAILS b
                    ON b.EMP_ID = a.EMP_ID
                   AND b.CUST_ID = a.CUST_ID
                   AND b.DAY_DATE = a.DAY_DATE
                   AND b.DAYPLAN_ID = a.DAYPLAN_ID
                INNER JOIN SFM_DAY_PLANS p
                    ON p.EMP_ID = a.EMP_ID
                   AND p.DAY_DATE = a.DAY_DATE
                   AND p.ID = a.DAYPLAN_ID
                WHERE a.EMP_ID = :employee_id
                  AND a.DAY_DATE >= TO_DATE(:from_date, 'YYYY-MM-DD')
                  AND a.DAY_DATE < TO_DATE(:to_date, 'YYYY-MM-DD')
                GROUP BY a.CUST_ID, b.ORDER_TO, a.DAYPLAN_ID, a.DAY_DATE, b.CONFIRM_OTP,
                         p.STATUS, b.OTP_NO, b.VERIFY_ON

                UNION ALL

                SELECT a.CUST_ID, b.ORDER_TO AS ORDER_TO_ID,
                       a.DAYPLAN_ID AS PLAN_ID,
                       SUM(a.POBAMT) AS AMOUNT, a.DAY_DATE,
                       b.CONFIRM_OTP, p.STATUS AS PLAN_STATUS,
                       b.OTP_NO, b.VERIFY_ON, 'CS' AS SOURCE_TABLE
                FROM SFM_DAY_PLANS_CUSTSOB a
                INNER JOIN SFM_DAY_PLANS_DETAILS b
                    ON b.EMP_ID = a.EMP_ID
                   AND b.CUST_ID = a.CUST_ID
                   AND b.DAY_DATE = a.DAY_DATE
                   AND b.DAYPLAN_ID = a.DAYPLAN_ID
                INNER JOIN SFM_DAY_PLANS p
                    ON p.EMP_ID = a.EMP_ID
                   AND p.DAY_DATE = a.DAY_DATE
                   AND p.ID = a.DAYPLAN_ID
                WHERE a.EMP_ID = :employee_id
                  AND a.DAY_DATE >= TO_DATE(:from_date, 'YYYY-MM-DD')
                  AND a.DAY_DATE < TO_DATE(:to_date, 'YYYY-MM-DD')
                GROUP BY a.CUST_ID, b.ORDER_TO, a.DAYPLAN_ID, a.DAY_DATE, b.CONFIRM_OTP,
                         p.STATUS, b.OTP_NO, b.VERIFY_ON

                UNION ALL

                SELECT o.CUST_ID, o.CUST_ID_ORDER AS ORDER_TO_ID,
                       NULL AS PLAN_ID,
                       o.TOT_POBAMT AS AMOUNT, o.DAY_DATE,
                       o.CONFIRM_OTP, o.STATUS AS PLAN_STATUS,
                       o.OTP_NO, o.VERIFY_ON, 'PO' AS SOURCE_TABLE
                FROM SFM_CUST_POB_ORDER o
                WHERE o.EMP_ID = :employee_id
                  AND o.DAY_DATE >= TO_DATE(:from_date, 'YYYY-MM-DD')
                  AND o.DAY_DATE < TO_DATE(:to_date, 'YYYY-MM-DD')
            )
            SELECT o.CUST_ID,
                   INITCAP(c.CUST_NAME) AS CUSTOMER_NAME,
                   o.ORDER_TO_ID,
                   NVL(INITCAP(order_to.CUST_NAME), TO_CHAR(o.ORDER_TO_ID)) AS ORDER_TO_NAME,
                   o.PLAN_ID,
                   TO_CHAR(o.DAY_DATE, 'YYYY-MM-DD') AS ORDER_DATE,
                   o.AMOUNT,
                   NVL(
                       GET_CUST_OBTYPE(o.CUST_ID, :geo_id, :division_id),
                       'P'
                   ) AS ORDER_TYPE,
                   o.PLAN_STATUS,
                   o.CONFIRM_OTP,
                   o.OTP_NO,
                   TO_CHAR(o.VERIFY_ON, 'YYYY-MM-DD HH:MI AM') AS VERIFIED_AT,
                   o.SOURCE_TABLE
            FROM source_orders o
            INNER JOIN SFM_CUSTOMER_INFO c ON c.ID = o.CUST_ID
            LEFT JOIN SFM_CUSTOMER_INFO order_to ON order_to.ID = o.ORDER_TO_ID
            ORDER BY o.DAY_DATE, c.CUST_NAME, o.CUST_ID
            SQL, [
                'employee_id' => $employeeId,
                'from_date' => $firstDate->format('Y-m-d'),
                'to_date' => $nextMonth->format('Y-m-d'),
                'geo_id' => $geo['GEO_ID'],
                'division_id' => $divisions[0],
            ]);

        $todayRow = $this->fetchOne(
            "SELECT TO_CHAR(TRUNC(SYSDATE), 'YYYY-MM-DD') AS TODAY FROM DUAL"
        );
        if ($todayRow === null || empty($todayRow['TODAY'])) {
            throw new RuntimeException('The database date could not be read.');
        }
        $today = (string) $todayRow['TODAY'];

        $days = [];
        for ($date = $firstDate; $date < $nextMonth; $date = $date->modify('+1 day')) {
            $key = $date->format('Y-m-d');
            $days[$key] = [
                'date' => $key,
                'day' => $date->format('d'),
                'is_future' => $key > $today,
                'customer_count' => 0,
                'total' => 0.0,
                'pob_total' => 0.0,
                'sob_total' => 0.0,
                'customers' => [],
                'orders' => [],
            ];
        }

        foreach ($rows as $row) {
            $date = (string) $row['ORDER_DATE'];
            if (!isset($days[$date])) {
                continue;
            }
            $customerId = (string) $row['CUST_ID'];
            $type = strtoupper(trim((string) $row['ORDER_TYPE'])) === 'S' ? 'SOB' : 'POB';

            $amount = (float) ($row['AMOUNT'] ?? 0);
            $days[$date]['orders'][] = [
                'customer_id' => (int) $row['CUST_ID'],
                'customer' => (string) $row['CUSTOMER_NAME'],
                'order_to' => (string) ($row['ORDER_TO_NAME'] ?? ''),
                'type' => $type,
                'amount' => $amount,
                'status' => (string) ($row['PLAN_STATUS'] ?? ''),
                'process_flag' => (string) ($row['CONFIRM_OTP'] ?? 'N'),
                'otp_available' => !empty($row['OTP_NO']),
                'verified_at' => (string) ($row['VERIFIED_AT'] ?? ''),
                'source' => (string) $row['SOURCE_TABLE'],
                'plan_id' => isset($row['PLAN_ID']) ? (int) $row['PLAN_ID'] : null,
            ];
            $days[$date]['customers'][$customerId] = true;
            $days[$date]['total'] += $amount;
            $days[$date][strtolower($type) . '_total'] += $amount;
        }

        foreach ($days as &$day) {
            $day['customer_count'] = count($day['customers']);
            unset($day['customers']);
        }
        unset($day);

        return [
            'month' => $month,
            'label' => $firstDate->format('F Y'),
            'days' => array_values($days),
        ];
    }

    private function month(string $value): string
    {
        if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $value)) {
            throw new InvalidArgumentException('month must use YYYY-MM format.');
        }
        return $value;
    }
}
