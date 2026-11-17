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
                       b.ID AS ORDER_ID,
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
                GROUP BY a.CUST_ID, b.ORDER_TO, b.ID, a.DAYPLAN_ID, a.DAY_DATE, b.CONFIRM_OTP,
                         p.STATUS, b.OTP_NO, b.VERIFY_ON

                UNION ALL

                SELECT a.CUST_ID, b.ORDER_TO AS ORDER_TO_ID,
                       b.ID AS ORDER_ID,
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
                GROUP BY a.CUST_ID, b.ORDER_TO, b.ID, a.DAYPLAN_ID, a.DAY_DATE, b.CONFIRM_OTP,
                         p.STATUS, b.OTP_NO, b.VERIFY_ON

                UNION ALL

                SELECT o.CUST_ID, o.CUST_ID_ORDER AS ORDER_TO_ID,
                       o.ID AS ORDER_ID,
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
                   o.ORDER_ID,
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
                'order_id' => (int) $row['ORDER_ID'],
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

    public function options(int $employeeId, ?int $customerId = null): array
    {
        $today = $this->fetchOne(
            "SELECT TO_CHAR(TRUNC(SYSDATE), 'YYYY-MM-DD') AS TODAY FROM DUAL"
        );
        if ($today === null || empty($today['TODAY'])) {
            throw new RuntimeException('The database date could not be read.');
        }

        $divisions = $this->employeeAuthModel()->getEmpDivisions($employeeId);
        if ($divisions === []) {
            throw new RuntimeException('No active division is configured for this employee.');
        }
        $geo = $this->employeeGeo($employeeId);
        $customers = $this->fetchAll(<<<'SQL'
            SELECT DISTINCT c.ID AS CUSTOMER_ID, INITCAP(c.CUST_NAME) AS CUSTOMER_NAME,
                   c.CUST_CODE
            FROM SFM_ROUTE_PLANS_DET d
            INNER JOIN SFM_ROUTE_PLANS r ON r.ID = d.ROUTE_ID
            INNER JOIN SFM_CUSTOMER_INFO c ON c.ID = d.CUST_ID
            WHERE r.HQ_ID = :geo_id
              AND r.DIVSN_ID IN (:division_one)
              AND d.STATUS = 'A'
              AND r.STATUS = 'A'
              AND TRUNC(SYSDATE) BETWEEN TRUNC(d.EFFEC_FROM)
                  AND NVL(TRUNC(d.EFFEC_TO), DATE '3000-01-01')
            ORDER BY CUSTOMER_NAME, c.ID
            SQL, [
                'geo_id' => $geo['GEO_ID'],
                'division_one' => $divisions[0],
            ]);

        $products = $this->fetchAll(<<<'SQL'
            SELECT DISTINCT i.ID AS PRODUCT_ID, i.DESCR AS PRODUCT_NAME,
                   g.GRP_DESC AS CATEGORY_NAME, r.RATE
            FROM SFM_ITEM_MASTER i
            INNER JOIN SFM_ITEM_DIVISIONS d ON d.ITEM_ID = i.ID
            INNER JOIN SFM_ITEM_RATES r
                ON r.ITEM_ID = i.ID AND r.DIVSN_ID = d.DIVSN_ID
            INNER JOIN SFM_ITEM_GROUP_MAP m ON m.ITEM_ID = i.ID
            INNER JOIN SFM_ITEM_GROUPS g ON g.GRP_ID = m.GRP_ID
            WHERE i.ITEM_TYPE = 'Sellable Product'
              AND i.STATUS = 'A'
              AND d.DIVSN_ID = :division_id
              AND TRUNC(SYSDATE) BETWEEN TRUNC(d.EFFEC_FROM)
                  AND NVL(TRUNC(d.EFFEC_TO), DATE '3000-01-01')
              AND TRUNC(SYSDATE) BETWEEN TRUNC(r.EFFEC_FROM)
                  AND NVL(TRUNC(r.EFFEC_TO), DATE '3000-01-01')
              AND TRUNC(SYSDATE) BETWEEN TRUNC(m.EFFEC_FROM)
                  AND NVL(TRUNC(m.EFFEC_TO), DATE '3000-01-01')
            ORDER BY g.GRP_DESC, i.DESCR
            SQL, ['division_id' => $divisions[0]]);

        $specialCustomer = false;
        if ($customerId !== null) {
            $this->assertCustomerAvailable($customerId, $geo, $divisions[0]);
            $customer = $this->fetchOne(<<<'SQL'
                SELECT CTYP_ID
                FROM SFM_CUST_HQ
                WHERE CUST_ID = :customer_id
                  AND HQ_ID = :geo_id
                  AND DIVSN_ID = :division_id
                SQL, [
                    'customer_id' => $customerId,
                    'geo_id' => $geo['GEO_ID'],
                    'division_id' => $divisions[0],
                ]);
            if ($customer === null) {
                throw new InvalidArgumentException('The selected customer is not available.');
            }

            $specialCustomer = in_array((int) $customer['CTYP_ID'], [11, 19, 20], true);
        }
        $orderToSql = $specialCustomer
            ? <<<'SQL'
                SELECT DISTINCT d.PARTY_ID AS ORDER_TO_ID,
                       p.PARTY_NAME || ' (' || p.PARTY_CODE || ')' AS ORDER_TO_NAME
                FROM SFM_PARTY_HQ_DIVISION d
                INNER JOIN SFM_PARTY_MASTER p ON p.ID = d.PARTY_ID
                WHERE TRUNC(SYSDATE) BETWEEN TRUNC(d.EFFEC_FROM)
                    AND NVL(TRUNC(d.EFFEC_TO), DATE '3000-01-01')
                  AND d.DIVSN_ID = :division_id
                  AND d.PARTY_CODE IN (
                      SELECT DISTINCT s.SSTK_CODE
                      FROM SFM_PARTY_HQ_DIVISION s
                      INNER JOIN SFM_PARTY_MASTER sp ON sp.ID = s.PARTY_ID
                      WHERE s.HQ_LABEL = :hq_label
                        AND s.DIVSN_ID = :division_id
                        AND s.KYC_FLAG = 'Y'
                        AND sp.PARTY_FLAG <> 'OS'
                        AND TRUNC(SYSDATE) BETWEEN TRUNC(s.EFFEC_FROM)
                            AND NVL(TRUNC(s.EFFEC_TO), DATE '3000-01-01')
                  )
                  AND d.KYC_FLAG = 'Y'
                  AND p.PARTY_FLAG <> 'OS'
                ORDER BY ORDER_TO_NAME
                SQL
            : <<<'SQL'
                SELECT DISTINCT d.PARTY_ID AS ORDER_TO_ID,
                       p.PARTY_NAME || ' (' || p.PARTY_CODE || ')' AS ORDER_TO_NAME
                FROM SFM_PARTY_HQ_DIVISION d
                INNER JOIN SFM_PARTY_MASTER p ON p.ID = d.PARTY_ID
                WHERE d.HQ_LABEL = :hq_label
                  AND d.DIVSN_ID = :division_id
                  AND d.KYC_FLAG = 'Y'
                  AND p.PARTY_FLAG <> 'OS'
                  AND TRUNC(SYSDATE) BETWEEN TRUNC(d.EFFEC_FROM)
                      AND NVL(TRUNC(d.EFFEC_TO), DATE '3000-01-01')
                ORDER BY ORDER_TO_NAME
                SQL;
        $orderTo = $this->fetchAll($orderToSql, [
            'hq_label' => $geo['GEO_LABEL'],
            'division_id' => $divisions[0],
        ]);

        return [
            'date' => (string) $today['TODAY'],
            'customers' => array_map(static fn (array $row): array => [
                'customer_id' => (int) $row['CUSTOMER_ID'],
                'name' => (string) $row['CUSTOMER_NAME'],
                'code' => (string) $row['CUST_CODE'],
            ], $customers),
            'order_to_options' => array_map(static fn (array $row): array => [
                'id' => (int) $row['ORDER_TO_ID'],
                'name' => (string) $row['ORDER_TO_NAME'],
            ], $orderTo),
            'products' => array_map(static fn (array $row): array => [
                'product_id' => (int) $row['PRODUCT_ID'],
                'name' => (string) $row['PRODUCT_NAME'],
                'category' => (string) $row['CATEGORY_NAME'],
                'rate' => (float) $row['RATE'],
            ], $products),
        ];
    }

    public function order(int $employeeId, string $source, int $orderId): ?array
    {
        $source = strtoupper(trim($source));
        if (!in_array($source, ['PO', 'CP', 'CS'], true)) {
            throw new InvalidArgumentException('source must be PO, CP or CS.');
        }
        if ($orderId <= 0) {
            throw new InvalidArgumentException('order_id must be a positive integer.');
        }

        if ($source === 'PO') {
            $header = $this->fetchOne(<<<'SQL'
                SELECT o.ID AS ORDER_ID, o.EMP_ID, o.GEO_ID, o.DIVSN_ID,
                       TO_CHAR(o.DAY_DATE, 'YYYY-MM-DD') AS ORDER_DATE,
                       TO_CHAR(o.DAY_DATE, 'DD-Mon-YYYY HH:MI AM') AS ORDER_DATE_LABEL,
                       o.CUST_ID AS CUSTOMER_ID,
                       INITCAP(c.CUST_NAME) AS CUSTOMER_NAME, c.CUST_CODE,
                       o.CUST_ID_ORDER AS ORDER_TO_ID,
                       NVL(p.PARTY_NAME || ' (' || p.PARTY_CODE || ')',
                           TO_CHAR(o.CUST_ID_ORDER)) AS ORDER_TO_NAME,
                       o.TOT_POBAMT AS AMOUNT, o.STATUS AS STATUS_CODE,
                       o.PROCESS_FLAG, o.CONFIRM_OTP, o.OTP_NO,
                       TO_CHAR(o.VERIFY_ON, 'YYYY-MM-DD HH:MI AM') AS VERIFIED_AT,
                       NVL(GET_CUST_OBTYPE(o.CUST_ID, o.GEO_ID, o.DIVSN_ID), 'P')
                           AS OB_TYPE,
                       TRIM(e.FNAME || ' ' || e.MNAME || ' ' || e.LNAME) AS EMPLOYEE_NAME
                FROM SFM_CUST_POB_ORDER o
                INNER JOIN SFM_CUSTOMER_INFO c ON c.ID = o.CUST_ID
                LEFT JOIN SFM_PARTY_MASTER p ON p.ID = o.CUST_ID_ORDER
                INNER JOIN SFM_EMPLOYEE_INFO e ON e.ID = o.EMP_ID
                WHERE o.ID = :order_id AND o.EMP_ID = :employee_id
                SQL, ['order_id' => $orderId, 'employee_id' => $employeeId]);

            if ($header === null) {
                return null;
            }
            $items = $this->fetchAll(<<<'SQL'
                SELECT d.ITEM_ID AS PRODUCT_ID, i.DESCR AS PRODUCT_NAME,
                       d.RATE, d.QTY AS QUANTITY, d.FREE_QTY AS FREE_QUANTITY,
                       d.POBAMT AS AMOUNT
                FROM SFM_CUST_POB_ORDERDET d
                INNER JOIN SFM_ITEM_MASTER i ON i.ID = d.ITEM_ID
                WHERE d.ORDER_ID = :order_id
                ORDER BY i.DESCR
                SQL, ['order_id' => $orderId]);
        } else {
            $divisions = $this->employeeAuthModel()->getEmpDivisions($employeeId);
            if ($divisions === []) {
                throw new RuntimeException('No active division is configured for this employee.');
            }
            $header = $this->fetchOne(<<<'SQL'
                SELECT d.ID AS ORDER_ID, d.EMP_ID, d.HQ_ID AS GEO_ID,
                       TO_CHAR(d.DAY_DATE, 'YYYY-MM-DD') AS ORDER_DATE,
                       TO_CHAR(d.DAY_DATE, 'DD-Mon-YYYY HH:MI AM') AS ORDER_DATE_LABEL,
                       d.CUST_ID AS CUSTOMER_ID,
                       INITCAP(c.CUST_NAME) AS CUSTOMER_NAME, c.CUST_CODE,
                       d.ORDER_TO AS ORDER_TO_ID,
                       CASE WHEN d.ORDER_TO = 0 THEN 'TeamSDL'
                            ELSE NVL(INITCAP(order_to.CUST_NAME),
                                     NVL(p.PARTY_NAME || ' (' || p.PARTY_CODE || ')',
                                         TO_CHAR(d.ORDER_TO)))
                       END AS ORDER_TO_NAME,
                       d.TOTAL_POB AS AMOUNT, dp.STATUS AS STATUS_CODE,
                       d.CONFIRM_OTP AS PROCESS_FLAG, d.CONFIRM_OTP,
                       d.OTP_NO,
                       TO_CHAR(d.VERIFY_ON, 'YYYY-MM-DD HH:MI AM') AS VERIFIED_AT,
                       NVL(GET_CUST_OBTYPE(d.CUST_ID, d.HQ_ID, :division_id), 'P')
                           AS OB_TYPE,
                       TRIM(e.FNAME || ' ' || e.MNAME || ' ' || e.LNAME) AS EMPLOYEE_NAME,
                       d.DAYPLAN_ID AS PLAN_ID
                FROM SFM_DAY_PLANS_DETAILS d
                INNER JOIN SFM_DAY_PLANS dp
                    ON dp.ID = d.DAYPLAN_ID
                   AND dp.EMP_ID = d.EMP_ID
                   AND dp.DAY_DATE = d.DAY_DATE
                INNER JOIN SFM_CUSTOMER_INFO c ON c.ID = d.CUST_ID
                LEFT JOIN SFM_CUSTOMER_INFO order_to ON order_to.ID = d.ORDER_TO
                LEFT JOIN SFM_PARTY_MASTER p ON p.ID = d.ORDER_TO
                INNER JOIN SFM_EMPLOYEE_INFO e ON e.ID = d.EMP_ID
                WHERE d.ID = :order_id AND d.EMP_ID = :employee_id
                SQL, [
                    'order_id' => $orderId,
                    'employee_id' => $employeeId,
                    'division_id' => $divisions[0],
                ]);
            if ($header === null) {
                return null;
            }
            if (($source === 'CP' && strtoupper((string) $header['OB_TYPE']) !== 'P')
                || ($source === 'CS' && strtoupper((string) $header['OB_TYPE']) !== 'S')) {
                return null;
            }
            $itemTable = $source === 'CP'
                ? 'SFM_DAY_PLANS_CUSTPOB'
                : 'SFM_DAY_PLANS_CUSTSOB';
            $freeQuantity = $source === 'CP' ? 'd.FREE_QTY' : '0';
            $items = $this->fetchAll(<<<SQL
                SELECT d.ITEM_ID AS PRODUCT_ID, i.DESCR AS PRODUCT_NAME,
                       d.RATE, d.QTY AS QUANTITY, $freeQuantity AS FREE_QUANTITY,
                       d.POBAMT AS AMOUNT
                FROM $itemTable d
                INNER JOIN SFM_ITEM_MASTER i ON i.ID = d.ITEM_ID
                WHERE d.DAYPLAN_ID = :plan_id
                  AND d.EMP_ID = :employee_id
                  AND d.CUST_ID = :customer_id
                  AND TRUNC(d.DAY_DATE) = TO_DATE(:order_date, 'YYYY-MM-DD')
                ORDER BY i.DESCR
                SQL, [
                    'plan_id' => $header['PLAN_ID'],
                    'employee_id' => $employeeId,
                    'customer_id' => $header['CUSTOMER_ID'],
                    'order_date' => $header['ORDER_DATE'],
                ]);
        }

        return [
            'order_id' => (int) $header['ORDER_ID'],
            'source' => $source,
            'date' => (string) $header['ORDER_DATE'],
            'date_label' => (string) $header['ORDER_DATE_LABEL'],
            'customer_id' => (int) $header['CUSTOMER_ID'],
            'customer' => (string) $header['CUSTOMER_NAME'],
            'customer_code' => (string) ($header['CUST_CODE'] ?? ''),
            'order_to_id' => (int) ($header['ORDER_TO_ID'] ?? 0),
            'order_to' => (string) ($header['ORDER_TO_NAME'] ?? ''),
            'type' => strtoupper(trim((string) $header['OB_TYPE'])) === 'S' ? 'SOB' : 'POB',
            'amount' => (float) ($header['AMOUNT'] ?? 0),
            'status' => (string) ($header['STATUS_CODE'] ?? ''),
            'process_flag' => (string) ($header['PROCESS_FLAG'] ?? ''),
            'verified_at' => empty($header['VERIFIED_AT'])
                ? ''
                : (string) $header['VERIFIED_AT'],
            'employee' => (string) ($header['EMPLOYEE_NAME'] ?? ''),
            'can_edit' => $source === 'PO'
                && empty($header['PROCESS_FLAG'])
                && ($header['STATUS_CODE'] === null || $header['STATUS_CODE'] === 'N'),
            'items' => array_map(static fn (array $item): array => [
                'product_id' => (int) $item['PRODUCT_ID'],
                'product' => (string) $item['PRODUCT_NAME'],
                'rate' => (float) $item['RATE'],
                'quantity' => (float) $item['QUANTITY'],
                'free_quantity' => (float) $item['FREE_QUANTITY'],
                'amount' => (float) $item['AMOUNT'],
            ], $items),
        ];
    }

    public function saveDraft(int $employeeId, string $loginId, array $input): array
    {
        $customerId = $this->positiveId($input['customer_id'] ?? null, 'customer_id');
        $orderToId = $this->positiveId($input['order_to_id'] ?? null, 'order_to_id');
        $orderId = isset($input['order_id'])
            ? $this->positiveId($input['order_id'], 'order_id')
            : null;
        $date = $this->date((string) ($input['date'] ?? ''));
        if (!is_array($input['items'] ?? null) || $input['items'] === []) {
            throw new InvalidArgumentException('At least one order item is required.');
        }
        if (count($input['items']) > 300) {
            throw new InvalidArgumentException('An order cannot contain more than 300 items.');
        }

        $today = $this->fetchOne(
            "SELECT TO_CHAR(TRUNC(SYSDATE), 'YYYY-MM-DD') AS TODAY FROM DUAL"
        );
        if ($today === null || $date !== (string) $today['TODAY']) {
            throw new InvalidArgumentException('New and draft orders can only be saved for today.');
        }

        $divisions = $this->employeeAuthModel()->getEmpDivisions($employeeId);
        if ($divisions === []) {
            throw new RuntimeException('No active division is configured for this employee.');
        }
        $geo = $this->employeeGeo($employeeId);
        $this->assertCustomerAvailable($customerId, $geo, $divisions[0]);
        $this->assertOrderToAvailable($customerId, $orderToId, $geo, $divisions[0]);
        $lines = $this->validateLines($input['items'], $divisions[0]);
        $amount = array_sum(array_map(
            static fn (array $line): float => $line['rate'] * $line['quantity'],
            $lines
        ));
        if ($amount <= 0) {
            throw new InvalidArgumentException('The order total must be greater than zero.');
        }

        $savedId = $this->transaction(function () use (
            $employeeId,
            $loginId,
            $customerId,
            $orderToId,
            $orderId,
            $date,
            $geo,
            $divisions,
            $lines,
            $amount
        ): int {
            if ($orderId === null) {
                $savedId = (int) $this->executeReturning(<<<'SQL'
                    INSERT INTO SFM_CUST_POB_ORDER (
                        ID, EMP_ID, GEO_ID, DIVSN_ID, DAY_DATE, CUST_ID,
                        CUST_ID_ORDER, TOT_POBAMT, CHG_ON, CHG_BY
                    ) VALUES (
                        NULL, :employee_id, :geo_id, :division_id,
                        TO_DATE(:order_date, 'YYYY-MM-DD'), :customer_id,
                        :order_to_id, 0, SYSDATE, :login_id
                    )
                    RETURNING ID INTO :return_id
                    SQL, [
                        'employee_id' => $employeeId,
                        'geo_id' => $geo['GEO_ID'],
                        'division_id' => $divisions[0],
                        'order_date' => $date,
                        'customer_id' => $customerId,
                        'order_to_id' => $orderToId,
                        'login_id' => $loginId,
                    ], ':return_id');
            } else {
                $existing = $this->fetchOne(<<<'SQL'
                    SELECT ID
                    FROM SFM_CUST_POB_ORDER
                    WHERE ID = :order_id
                      AND EMP_ID = :employee_id
                      AND TRUNC(DAY_DATE) = TRUNC(SYSDATE)
                      AND PROCESS_FLAG IS NULL
                    FOR UPDATE
                    SQL, ['order_id' => $orderId, 'employee_id' => $employeeId]);
                if ($existing === null) {
                    throw new InvalidArgumentException('This order cannot be edited.');
                }
                $savedId = $orderId;
                $this->execute(<<<'SQL'
                    UPDATE SFM_CUST_POB_ORDER
                    SET CUST_ID = :customer_id,
                        CUST_ID_ORDER = :order_to_id,
                        TOT_POBAMT = 0,
                        CHG_ON = SYSDATE,
                        CHG_BY = :login_id
                    WHERE ID = :order_id
                      AND EMP_ID = :employee_id
                    SQL, [
                        'customer_id' => $customerId,
                        'order_to_id' => $orderToId,
                        'login_id' => $loginId,
                        'order_id' => $savedId,
                        'employee_id' => $employeeId,
                    ]);
                $this->execute(
                    'DELETE FROM SFM_CUST_POB_ORDERDET WHERE ORDER_ID = :order_id',
                    ['order_id' => $savedId]
                );
            }

            foreach ($lines as $line) {
                $this->execute(<<<'SQL'
                    INSERT INTO SFM_CUST_POB_ORDERDET (
                        ID, ORDER_ID, ITEM_ID, QTY, RATE, FREE_QTY,
                        POBAMT, CHG_ON, CHG_BY
                    ) VALUES (
                        NULL, :order_id, :item_id, :quantity, :rate,
                        :free_quantity, :amount, SYSDATE, :login_id
                    )
                    SQL, [
                        'order_id' => $savedId,
                        'item_id' => $line['product_id'],
                        'quantity' => $line['quantity'],
                        'rate' => $line['rate'],
                        'free_quantity' => $line['free_quantity'],
                        'amount' => $line['rate'] * $line['quantity'],
                        'login_id' => $loginId,
                    ]);
            }

            $this->execute(<<<'SQL'
                UPDATE SFM_CUST_POB_ORDER
                SET TOT_POBAMT = :amount, STATUS = 'N',
                    CHG_ON = SYSDATE, CHG_BY = :login_id
                WHERE ID = :order_id AND EMP_ID = :employee_id
                SQL, [
                    'amount' => $amount,
                    'login_id' => $loginId,
                    'order_id' => $savedId,
                    'employee_id' => $employeeId,
                ]);

            return $savedId;
        });

        return $this->order($employeeId, 'PO', $savedId)
            ?? throw new RuntimeException('The saved order could not be loaded.');
    }

    public function confirm(int $employeeId, int $orderId): array
    {
        if ($orderId <= 0) {
            throw new InvalidArgumentException('order_id must be a positive integer.');
        }
        $this->transaction(function () use ($employeeId, $orderId): void {
            $order = $this->fetchOne(<<<'SQL'
                SELECT ID, TO_CHAR(DAY_DATE, 'DD-Mon-YYYY') AS ORDER_DATE
                FROM SFM_CUST_POB_ORDER
                WHERE ID = :order_id
                  AND EMP_ID = :employee_id
                  AND TRUNC(DAY_DATE) = TRUNC(SYSDATE)
                  AND PROCESS_FLAG IS NULL
                FOR UPDATE
                SQL, ['order_id' => $orderId, 'employee_id' => $employeeId]);
            if ($order === null) {
                throw new InvalidArgumentException('This order cannot be confirmed.');
            }
            $items = $this->fetchOne(<<<'SQL'
                SELECT COUNT(1) AS ITEM_COUNT
                FROM SFM_CUST_POB_ORDERDET
                WHERE ORDER_ID = :order_id
                SQL, ['order_id' => $orderId]);
            if ((int) ($items['ITEM_COUNT'] ?? 0) === 0) {
                throw new InvalidArgumentException('Add at least one product before confirming.');
            }

            $this->execute(<<<'SQL'
                UPDATE SFM_CUST_POB_ORDER
                SET STATUS = 'A', PROCESS_FLAG = 'N',
                    CHG_ON = SYSDATE
                WHERE ID = :order_id AND EMP_ID = :employee_id
                SQL, ['order_id' => $orderId, 'employee_id' => $employeeId]);
            $this->execute(<<<'SQL'
                BEGIN
                    PROCESS_POBSOB(:order_date, NULL, NULL, :order_id);
                END;
                SQL, ['order_date' => $order['ORDER_DATE'], 'order_id' => $orderId]);
        });

        return $this->order($employeeId, 'PO', $orderId)
            ?? throw new RuntimeException('The confirmed order could not be loaded.');
    }

    private function employeeAuthModel(): EmployeeAuthModel
    {
        return $this->employeeAuthModel ?? new EmployeeAuthModel($this->db);
    }

    private function employeeGeo(int $employeeId): array
    {
        $geo = $this->fetchOne(<<<'SQL'
            SELECT GEO_ID, GEO_LABEL
            FROM (
                SELECT g.GEO_ID, g.GEO_LABEL
                FROM TABLE(
                    GEOEMP.LIST_DOWN(
                        :employee_id, NULL, TRUNC(SYSDATE)
                    )
                ) g
                WHERE g.EMP_ID = :employee_id
            )
            WHERE ROWNUM = 1
            SQL, ['employee_id' => $employeeId]);
        if ($geo === null || empty($geo['GEO_ID'])) {
            throw new RuntimeException('No active geography is configured for this employee.');
        }
        return $geo;
    }

    private function assertCustomerAvailable(
        int $customerId,
        array $geo,
        int $divisionId
    ): void {
        $customer = $this->fetchOne(<<<'SQL'
            SELECT c.ID
            FROM SFM_ROUTE_PLANS_DET d
            INNER JOIN SFM_ROUTE_PLANS r ON r.ID = d.ROUTE_ID
            INNER JOIN SFM_CUSTOMER_INFO c ON c.ID = d.CUST_ID
            WHERE c.ID = :customer_id
              AND r.HQ_ID = :geo_id
              AND r.DIVSN_ID = :division_id
              AND d.STATUS = 'A'
              AND r.STATUS = 'A'
              AND TRUNC(SYSDATE) BETWEEN TRUNC(d.EFFEC_FROM)
                  AND NVL(TRUNC(d.EFFEC_TO), DATE '3000-01-01')
            SQL, [
                'customer_id' => $customerId,
                'geo_id' => $geo['GEO_ID'],
                'division_id' => $divisionId,
            ]);
        if ($customer === null) {
            throw new InvalidArgumentException('The selected customer is not available.');
        }
    }

    private function assertOrderToAvailable(
        int $customerId,
        int $orderToId,
        array $geo,
        int $divisionId
    ): void {
        $customer = $this->fetchOne(<<<'SQL'
            SELECT CTYP_ID
            FROM SFM_CUST_HQ
            WHERE CUST_ID = :customer_id
              AND HQ_ID = :geo_id
              AND DIVSN_ID = :division_id
            SQL, [
                'customer_id' => $customerId,
                'geo_id' => $geo['GEO_ID'],
                'division_id' => $divisionId,
            ]);
        if ($customer === null) {
            throw new InvalidArgumentException('The selected customer is not available.');
        }
        $specialCustomer = in_array((int) $customer['CTYP_ID'], [11, 19, 20], true);
        $filter = $specialCustomer
            ? <<<'SQL'
                AND d.PARTY_CODE IN (
                    SELECT s.SSTK_CODE
                    FROM SFM_PARTY_HQ_DIVISION s
                    INNER JOIN SFM_PARTY_MASTER sp ON sp.ID = s.PARTY_ID
                    WHERE s.HQ_LABEL = :hq_label
                      AND s.DIVSN_ID = :division_id
                      AND s.KYC_FLAG = 'Y'
                      AND sp.PARTY_FLAG <> 'OS'
                      AND TRUNC(SYSDATE) BETWEEN TRUNC(s.EFFEC_FROM)
                          AND NVL(TRUNC(s.EFFEC_TO), DATE '3000-01-01')
                )
                SQL
            : 'AND d.HQ_LABEL = :hq_label';
        $party = $this->fetchOne(<<<SQL
            SELECT d.PARTY_ID
            FROM SFM_PARTY_HQ_DIVISION d
            INNER JOIN SFM_PARTY_MASTER p ON p.ID = d.PARTY_ID
            WHERE d.PARTY_ID = :order_to_id
              AND d.DIVSN_ID = :division_id
              AND d.KYC_FLAG = 'Y'
              AND p.PARTY_FLAG <> 'OS'
              AND TRUNC(SYSDATE) BETWEEN TRUNC(d.EFFEC_FROM)
                  AND NVL(TRUNC(d.EFFEC_TO), DATE '3000-01-01')
              $filter
            SQL, [
                'order_to_id' => $orderToId,
                'division_id' => $divisionId,
                'hq_label' => $geo['GEO_LABEL'],
            ]);
        if ($party === null) {
            throw new InvalidArgumentException('The selected order-to party is not available.');
        }
    }

    private function validateLines(array $items, int $divisionId): array
    {
        $inputLines = [];
        $productIds = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                throw new InvalidArgumentException('Each order item must be an object.');
            }
            $productId = $this->positiveId($item['product_id'] ?? null, 'product_id');
            if (isset($productIds[$productId])) {
                throw new InvalidArgumentException('An order cannot contain a product more than once.');
            }
            $quantity = $this->quantity($item['quantity'] ?? 0, 'quantity');
            $freeQuantity = $this->quantity($item['free_quantity'] ?? 0, 'free_quantity');
            if ($quantity <= 0 || $quantity > 1000000 || $freeQuantity > 1000000) {
                throw new InvalidArgumentException('Each product must have a positive quantity no greater than 1000000.');
            }
            $inputLines[] = [
                'product_id' => $productId,
                'quantity' => $quantity,
                'free_quantity' => $freeQuantity,
            ];
            $productIds[$productId] = true;
        }

        $placeholders = [];
        $params = ['division_id' => $divisionId];
        foreach (array_keys($productIds) as $index => $productId) {
            $name = 'product_' . $index;
            $placeholders[] = ':' . $name;
            $params[$name] = $productId;
        }
        $productList = implode(', ', $placeholders);
        $products = $this->fetchAll(<<<SQL
            SELECT DISTINCT i.ID AS PRODUCT_ID, r.RATE
            FROM SFM_ITEM_MASTER i
            INNER JOIN SFM_ITEM_DIVISIONS d ON d.ITEM_ID = i.ID
            INNER JOIN SFM_ITEM_RATES r
                ON r.ITEM_ID = i.ID AND r.DIVSN_ID = d.DIVSN_ID
            INNER JOIN SFM_ITEM_GROUP_MAP m ON m.ITEM_ID = i.ID
            WHERE i.ID IN ($productList)
              AND i.ITEM_TYPE = 'Sellable Product'
              AND i.STATUS = 'A'
              AND d.DIVSN_ID = :division_id
              AND TRUNC(SYSDATE) BETWEEN TRUNC(d.EFFEC_FROM)
                  AND NVL(TRUNC(d.EFFEC_TO), DATE '3000-01-01')
              AND TRUNC(SYSDATE) BETWEEN TRUNC(r.EFFEC_FROM)
                  AND NVL(TRUNC(r.EFFEC_TO), DATE '3000-01-01')
              AND TRUNC(SYSDATE) BETWEEN TRUNC(m.EFFEC_FROM)
                  AND NVL(TRUNC(m.EFFEC_TO), DATE '3000-01-01')
            SQL, $params);
        $rates = [];
        foreach ($products as $product) {
            $rates[(int) $product['PRODUCT_ID']] = (float) $product['RATE'];
        }
        if (count($rates) !== count($inputLines)) {
            throw new InvalidArgumentException('One or more selected products are not available.');
        }
        foreach ($inputLines as &$line) {
            $line['rate'] = $rates[$line['product_id']];
        }
        unset($line);
        return $inputLines;
    }

    private function positiveId(mixed $value, string $field): int
    {
        if (filter_var($value, FILTER_VALIDATE_INT) === false || (int) $value <= 0) {
            throw new InvalidArgumentException("$field must be a positive integer.");
        }
        return (int) $value;
    }

    private function quantity(mixed $value, string $field): float
    {
        if (!is_numeric($value) || !is_finite((float) $value) || (float) $value < 0) {
            throw new InvalidArgumentException("$field must be a non-negative number.");
        }
        return (float) $value;
    }

    private function date(string $value): string
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if ($date === false || $date->format('Y-m-d') !== $value) {
            throw new InvalidArgumentException('date must use YYYY-MM-DD format.');
        }
        return $value;
    }

    private function month(string $value): string
    {
        if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $value)) {
            throw new InvalidArgumentException('month must use YYYY-MM format.');
        }
        return $value;
    }
}
