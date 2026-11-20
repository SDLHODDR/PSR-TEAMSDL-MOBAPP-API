<?php

declare(strict_types=1);

namespace App\Models;

use DateTimeImmutable;
use InvalidArgumentException;
use SDL\Framework\Database;
use SDL\Framework\Model;

final class DayPlanModel extends Model
{
    public function __construct(Database $db)
    {
        parent::__construct($db);
    }

    public function load(int $employeeId, string $date): array
    {
        $date = $this->date($date);
        $plan = $this->findPlan($employeeId, $date);
        $routeOptions = (new TourPlanModel($this->db))->routeOptions($employeeId, $date);
        $plannedRoute = $this->fetchOne(<<<'SQL'
            SELECT ROUTE_ID
            FROM (
                SELECT d.ROUTE_ID
                FROM SFM_TOUR_PLANS p
                INNER JOIN SFM_TOUR_PLANS_DET d ON d.TOUR_ID = p.ID
                WHERE p.EMP_ID = :employee_id
                  AND TRUNC(d.TOUR_DATE) = TO_DATE(:day_date, 'YYYY-MM-DD')
                  AND d.ROUTE_ID IS NOT NULL
                ORDER BY d.ID
            )
            WHERE ROWNUM = 1
            SQL, ['employee_id' => $employeeId, 'day_date' => $date]);

        return [
            'date' => $date,
            'planned_route_id' => $plannedRoute === null ? null : (int) $plannedRoute['ROUTE_ID'],
            'day_plan' => $plan === null ? null : $this->mapPlan($plan),
            'options' => $this->options($employeeId, $date),
            'routes' => $routeOptions['routes'],
            'listed_customers' => $plan === null ? [] : $this->listedCustomers((int) $plan['ID']),
            'unlisted_customers' => $plan === null ? [] : $this->unlistedCustomers((int) $plan['ID']),
        ];
    }

    public function options(int $employeeId, string $date): array
    {
        $date = $this->date($date);
        $towns = $this->fetchAll(<<<'SQL'
            SELECT DISTINCT t.TOWN_ID, t.TOWN_DESC
            FROM SFM_EMP_GEO h
            INNER JOIN SFM_GEO_HQ_TOWNS t ON t.HQ_ID = h.GEO_ID
            INNER JOIN SFM_GEO_ALLOWANCES a
                ON a.FRTOWN_ID = t.TOWN_ID AND a.HQ_ID = t.HQ_ID
            WHERE h.EMP_ID = :employee_id
              AND a.DIVSN_ID IN (
                  SELECT DIVSN_ID
                  FROM SFM_EMP_DIVISIONS
                  WHERE EMP_ID = :employee_id
                    AND TO_DATE(:day_date, 'YYYY-MM-DD') BETWEEN TRUNC(EFFEC_FROM)
                        AND NVL(TRUNC(EFFEC_TO), DATE '3000-01-01')
              )
              AND TO_DATE(:day_date, 'YYYY-MM-DD') BETWEEN TRUNC(t.EFFEC_FROM)
                  AND NVL(TRUNC(t.EFFEC_TO), DATE '3000-01-01')
            ORDER BY t.TOWN_DESC
            SQL, ['employee_id' => $employeeId, 'day_date' => $date]);
        $baseTown = $this->fetchOne(
            'SELECT BASE_TOWN_ID FROM SFM_EMPLOYEE_INFO WHERE ID = :employee_id',
            ['employee_id' => $employeeId]
        );
        $types = $this->fetchAll(
            'SELECT CTYP_ID, CTYP_DESC FROM SFM_CUST_TYPES ORDER BY SEQ, CTYP_DESC'
        );
        $qualifications = $this->fetchAll(
            'SELECT QUA_DESC FROM SFM_QUALIFICATIONS ORDER BY QUA_DESC'
        );
        $previousNames = $this->fetchAll(<<<'SQL'
            SELECT DISTINCT CUST_NAME
            FROM SFM_DAY_PLANS_UNLISTED
            WHERE EMP_ID = :employee_id
            ORDER BY CUST_NAME
            SQL, ['employee_id' => $employeeId]);

        return [
            'start_towns' => array_map(static fn (array $row): array => [
                'town_id' => (int) $row['TOWN_ID'],
                'name' => (string) $row['TOWN_DESC'],
            ], $towns),
            'base_town_id' => isset($baseTown['BASE_TOWN_ID']) ? (int) $baseTown['BASE_TOWN_ID'] : null,
            'customer_types' => array_map(static fn (array $row): array => [
                'id' => (int) $row['CTYP_ID'],
                'name' => (string) $row['CTYP_DESC'],
            ], $types),
            'qualifications' => array_map(static fn (array $row): string => (string) $row['QUA_DESC'], $qualifications),
            'previous_unlisted_names' => array_map(static fn (array $row): string => (string) $row['CUST_NAME'], $previousNames),
        ];
    }

    public function listedCustomersForRoute(int $employeeId, int $planId, int $routeId): ?array
    {
        $plan = $this->findOwnedPlan($employeeId, $planId);
        if ($plan === null) {
            return null;
        }
        $this->assertEditable($plan);

        $rows = $this->fetchAll(<<<'SQL'
            SELECT d.CUST_ID AS CUSTOMER_ID,
                   INITCAP(c.CUST_NAME) AS CUSTOMER_NAME,
                   ct.CTYP_DESC AS CUSTOMER_TYPE,
                   ct.OB_TYPE AS ORDER_TYPE,
                   get_custid_custtypeid(d.CUST_ID, r.HQ_ID, r.DIVSN_ID) AS CUSTOMER_TYPE_ID,
                   CASE WHEN v.ID IS NULL THEN 0 ELSE 1 END AS SELECTED
            FROM SFM_ROUTE_PLANS r
            INNER JOIN SFM_ROUTE_PLANS_DET d ON d.ROUTE_ID = r.ID
            INNER JOIN SFM_CUSTOMER_INFO c ON c.ID = d.CUST_ID
            LEFT JOIN SFM_CUST_TYPES ct
                ON ct.CTYP_ID = get_custid_custtypeid(d.CUST_ID, r.HQ_ID, r.DIVSN_ID)
            LEFT JOIN SFM_DAY_PLANS_DETAILS v
                ON v.DAYPLAN_ID = :plan_id
               AND v.CUST_ID = d.CUST_ID
               AND v.ROUTE_ID = r.ROUTE_SEQ
            WHERE r.ROUTE_SEQ = :route_id
              AND r.HQ_ID = get_empid_hqid(:employee_id, TO_DATE(:day_date, 'YYYY-MM-DD'))
              AND r.DIVSN_ID IN (
                  SELECT DIVSN_ID FROM SFM_EMP_DIVISIONS
                  WHERE EMP_ID = :employee_id
                    AND TO_DATE(:day_date, 'YYYY-MM-DD') BETWEEN TRUNC(EFFEC_FROM)
                        AND NVL(TRUNC(EFFEC_TO), DATE '3000-01-01')
              )
              AND r.STATUS = 'A'
              AND d.STATUS = 'A'
              AND TO_DATE(:day_date, 'YYYY-MM-DD') BETWEEN TRUNC(d.EFFEC_FROM)
                  AND NVL(TRUNC(d.EFFEC_TO), DATE '3000-01-01')
            ORDER BY get_custid_custtypesq(d.CUST_ID, r.HQ_ID, r.DIVSN_ID),
                     INITCAP(c.CUST_NAME)
            SQL, [
                'plan_id' => $planId,
                'route_id' => $routeId,
                'employee_id' => $employeeId,
                'day_date' => (string) $plan['DAY_DATE_TEXT'],
            ]);

        return [
            'route_id' => $routeId,
            'customers' => array_map(static fn (array $row): array => [
                'customer_id' => (int) $row['CUSTOMER_ID'],
                'name' => (string) $row['CUSTOMER_NAME'],
                'customer_type' => (string) ($row['CUSTOMER_TYPE'] ?? ''),
                'order_type' => (string) ($row['ORDER_TYPE'] ?? 'P'),
                'customer_type_id' => $row['CUSTOMER_TYPE_ID'] === null
                    ? null
                    : (int) $row['CUSTOMER_TYPE_ID'],
                'selected' => (int) $row['SELECTED'] === 1,
            ], $rows),
        ];
    }

    public function saveDraft(int $employeeId, string $loginId, array $input): array
    {
        $date = $this->date((string) ($input['date'] ?? ''));
        $remarks = trim((string) ($input['remarks'] ?? ''));
        $townValue = $input['start_town_id'] ?? null;
        $townId = $this->positiveId($townValue);
        if ($townValue !== null && $townId === null) {
            throw new InvalidArgumentException('start_town_id must be a positive integer.');
        }
        if ($remarks === '') {
            throw new InvalidArgumentException('remarks is required.');
        }
        if ($date > (new DateTimeImmutable('today'))->format('Y-m-d')) {
            throw new InvalidArgumentException('A day plan cannot be created for a future date.');
        }
        if (strlen($remarks) > 500) {
            throw new InvalidArgumentException('remarks must be no longer than 500 characters.');
        }
        $availableTowns = $this->options($employeeId, $date)['start_towns'];
        if ($availableTowns !== [] && $townId === null) {
            throw new InvalidArgumentException('start_town_id is required.');
        }
        if ($townId !== null && !in_array($townId, array_column($availableTowns, 'town_id'), true)) {
            throw new InvalidArgumentException('The selected start town is not available.');
        }

        return $this->transaction(function () use ($employeeId, $loginId, $date, $remarks, $townId): array {
            $existing = $this->findPlan($employeeId, $date, true);
            if ($existing !== null) {
                $this->assertEditable($existing);
                $this->execute(<<<'SQL'
                    UPDATE SFM_DAY_PLANS
                    SET START_TOWN = :town_id,
                        REMARK = :remarks,
                        CHG_ON = SYSDATE,
                        CHG_BY = :login_id
                    WHERE ID = :plan_id AND EMP_ID = :employee_id
                    SQL, [
                        'town_id' => $townId,
                        'remarks' => $remarks,
                        'login_id' => $loginId,
                        'plan_id' => (int) $existing['ID'],
                        'employee_id' => $employeeId,
                    ]);
            } else {
                $division = $this->fetchOne(<<<'SQL'
                    SELECT DIVSN_ID
                    FROM SFM_EMP_DIVISIONS
                    WHERE EMP_ID = :employee_id
                      AND TO_DATE(:day_date, 'YYYY-MM-DD') BETWEEN TRUNC(EFFEC_FROM)
                          AND NVL(TRUNC(EFFEC_TO), DATE '3000-01-01')
                    ORDER BY EFFEC_FROM DESC
                    FETCH FIRST 1 ROWS ONLY
                    SQL, ['employee_id' => $employeeId, 'day_date' => $date]);
                if ($division === null) {
                    throw new InvalidArgumentException('No active division is configured for this date.');
                }
                $this->executeReturning(<<<'SQL'
                    INSERT INTO SFM_DAY_PLANS (
                        ID, EMP_ID, DAY_DATE, START_TOWN, REMARK, UNLISTED_COUNT,
                        LISTED_COUNT, ACT_TYPE, VISIT_TYPE, PREVIOUS_READING,
                        CURRENT_READING, PERS_MILEAGE, AREA_COVER, CONV_AMT,
                        HQ_AMT, XHQ_AMT, OS_AMT, OTHER_AMT, STATUS, AUTH_BY,
                        AUTH_ON, CHG_ON, CHG_BY, DIVSN_ID, APP_DP
                    ) VALUES (
                        NULL, :employee_id, TO_DATE(:day_date, 'YYYY-MM-DD'),
                        :town_id, :remarks, 0, 0, 'C', NULL, NULL, NULL, NULL,
                        NULL, 0, 0, 0, 0, 0, 'N', NULL, NULL, SYSDATE,
                        :login_id, :division_id, 'M'
                    )
                    RETURNING ID INTO :return_id
                    SQL, [
                        'employee_id' => $employeeId,
                        'day_date' => $date,
                        'town_id' => $townId,
                        'remarks' => $remarks,
                        'login_id' => $loginId,
                        'division_id' => (int) $division['DIVSN_ID'],
                    ], ':return_id');
            }

            $plan = $this->findPlan($employeeId, $date);
            if ($plan === null) {
                throw new \RuntimeException('The saved day plan could not be reloaded.');
            }
            return ['day_plan' => $this->mapPlan($plan)];
        });
    }

    public function saveOpenRequest(int $employeeId, array $input): array
    {
        $date = $this->date((string) ($input['date'] ?? ''));
        $remarks = trim((string) ($input['remarks'] ?? ''));
        if ($remarks === '') {
            throw new InvalidArgumentException('remarks is required.');
        }
        if (strlen($remarks) > 500) {
            throw new InvalidArgumentException('remarks must be no longer than 500 characters.');
        }
        if ($date >= (new DateTimeImmutable('today'))->format('Y-m-d')) {
            throw new InvalidArgumentException('A delayed-reporting request must be for a previous date.');
        }

        $existing = $this->fetchOne(<<<'SQL'
            SELECT ID, STATUS
            FROM SFM_DAY_PLANS_OPEN
            WHERE EMP_ID = :employee_id
              AND TRUNC(DAY_DATE) = TO_DATE(:day_date, 'YYYY-MM-DD')
            ORDER BY ID DESC
            FETCH FIRST 1 ROW ONLY
            SQL, ['employee_id' => $employeeId, 'day_date' => $date]);
        if ($existing !== null && in_array((string) $existing['STATUS'], ['A', 'T'], true)) {
            throw new InvalidArgumentException('A delayed-reporting request already exists for this date.');
        }

        $this->execute(<<<'SQL'
            INSERT INTO SFM_DAY_PLANS_OPEN(
                ID, EMP_ID, DAY_DATE, REMARK, STATUS, DP_FILL_STATUS,
                TASK_ID, AUTH_BY, AUTH_ON, AUTH_REMARK, CHG_ON, CHG_BY
            ) VALUES (
                NULL, :employee_id, TO_DATE(:day_date, 'YYYY-MM-DD'),
                :remarks, 'T', NULL, NULL, NULL, NULL, NULL, SYSDATE, :employee_id
            )
            SQL, [
                'employee_id' => $employeeId,
                'day_date' => $date,
                'remarks' => $remarks,
            ]);

        return ['date' => $date, 'status' => 'submitted'];
    }

    public function addListedCustomers(int $employeeId, string $loginId, array $input): array
    {
        $planId = $this->requiredId($input['day_plan_id'] ?? null, 'day_plan_id');
        $routeId = $this->requiredId($input['route_id'] ?? null, 'route_id');
        $customerIds = $input['customer_ids'] ?? null;
        if (!is_array($customerIds)) {
            throw new InvalidArgumentException('customer_ids must be an array.');
        }
        $normalizedCustomerIds = [];
        foreach ($customerIds as $value) {
            $customerId = $this->positiveId($value);
            if ($customerId === null) {
                throw new InvalidArgumentException('customer_ids must contain positive integers.');
            }
            $normalizedCustomerIds[] = $customerId;
        }
        $customerIds = array_values(array_unique($normalizedCustomerIds));

        $plan = $this->findOwnedPlan($employeeId, $planId);
        if ($plan === null) {
            throw new InvalidArgumentException('The day plan was not found.');
        }
        $this->assertEditable($plan);
        $date = (string) $plan['DAY_DATE_TEXT'];
        $route = $this->fetchOne(<<<'SQL'
            SELECT ID, HQ_ID, TOWN_ID, ROUTE_SEQ, DIVSN_ID
            FROM SFM_ROUTE_PLANS
            WHERE ROUTE_SEQ = :route_id
              AND HQ_ID = get_empid_hqid(:employee_id, TO_DATE(:day_date, 'YYYY-MM-DD'))
              AND DIVSN_ID = :division_id
              AND STATUS = 'A'
            FETCH FIRST 1 ROWS ONLY
            SQL, [
                'route_id' => $routeId,
                'employee_id' => $employeeId,
                'day_date' => $date,
                'division_id' => (int) $plan['DIVSN_ID'],
            ]);
        if ($route === null) {
            throw new InvalidArgumentException('The selected route is not available.');
        }

        return $this->transaction(function () use ($employeeId, $loginId, $planId, $route, $customerIds, $date): array {
            $lockedPlan = $this->findOwnedPlan($employeeId, $planId, true);
            if ($lockedPlan === null) {
                throw new InvalidArgumentException('The day plan was not found.');
            }
            $this->assertEditable($lockedPlan);
            $existingVisits = $this->fetchAll(<<<'SQL'
                SELECT CUST_ID
                FROM SFM_DAY_PLANS_DETAILS
                WHERE DAYPLAN_ID = :plan_id AND ROUTE_ID = :route_id
                SQL, ['plan_id' => $planId, 'route_id' => (int) $route['ROUTE_SEQ']]);
            $selectedIds = array_fill_keys($customerIds, true);
            foreach ($existingVisits as $visit) {
                $existingCustomerId = (int) $visit['CUST_ID'];
                if (!isset($selectedIds[$existingCustomerId])) {
                    $this->deleteListedCustomer($planId, $existingCustomerId);
                }
            }

            foreach ($customerIds as $customerId) {
                $listed = $this->fetchOne(<<<'SQL'
                    SELECT CUST_ID
                    FROM SFM_ROUTE_PLANS_DET
                    WHERE ROUTE_ID = :route_plan_id
                      AND CUST_ID = :customer_id
                      AND STATUS = 'A'
                      AND TO_DATE(:day_date, 'YYYY-MM-DD') BETWEEN TRUNC(EFFEC_FROM)
                          AND NVL(TRUNC(EFFEC_TO), DATE '3000-01-01')
                    SQL, [
                        'route_plan_id' => (int) $route['ID'],
                        'customer_id' => $customerId,
                        'day_date' => $date,
                    ]);
                if ($listed === null) {
                    throw new InvalidArgumentException('A selected customer is not on the active route.');
                }
                $exists = $this->fetchOne(<<<'SQL'
                    SELECT ID FROM SFM_DAY_PLANS_DETAILS
                    WHERE DAYPLAN_ID = :plan_id AND CUST_ID = :customer_id
                    SQL, ['plan_id' => $planId, 'customer_id' => $customerId]);
                if ($exists === null) {
                    $this->execute(<<<'SQL'
                        INSERT INTO SFM_DAY_PLANS_DETAILS (
                            ID, DAYPLAN_ID, EMP_ID, DAY_DATE, WORK_ON, JT_EMP_ID,
                            HQ_ID, TOWN_ID, ROUTE_ID, CUST_ID, TOTAL_POB, CHG_ON, CHG_BY
                        ) VALUES (
                            NULL, :plan_id, :employee_id, TO_DATE(:day_date, 'YYYY-MM-DD'),
                            1, NULL, :hq_id, :town_id, :route_id, :customer_id, 0, SYSDATE, :login_id
                        )
                        SQL, [
                            'plan_id' => $planId,
                            'employee_id' => $employeeId,
                            'day_date' => $date,
                            'hq_id' => (int) $route['HQ_ID'],
                            'town_id' => (int) $route['TOWN_ID'],
                            'route_id' => (int) $route['ROUTE_SEQ'],
                            'customer_id' => $customerId,
                            'login_id' => $loginId,
                        ]);
                } else {
                    $this->execute(<<<'SQL'
                        UPDATE SFM_DAY_PLANS_DETAILS
                        SET HQ_ID = :hq_id,
                            TOWN_ID = :town_id,
                            ROUTE_ID = :route_id,
                            JT_EMP_ID = NULL,
                            CHG_ON = SYSDATE,
                            CHG_BY = :login_id
                        WHERE ID = :visit_id AND DAYPLAN_ID = :plan_id
                        SQL, [
                            'hq_id' => (int) $route['HQ_ID'],
                            'town_id' => (int) $route['TOWN_ID'],
                            'route_id' => (int) $route['ROUTE_SEQ'],
                            'login_id' => $loginId,
                            'visit_id' => (int) $exists['ID'],
                            'plan_id' => $planId,
                        ]);
                }
            }

            return ['day_plan_id' => $planId, 'customer_ids' => $customerIds];
        });
    }

    public function deleteListedCustomerForEmployee(
        int $employeeId,
        int $planId,
        int $customerId
    ): array {
        $plan = $this->findOwnedPlan($employeeId, $planId);
        if ($plan === null) {
            throw new InvalidArgumentException('The day plan was not found.');
        }
        $this->assertEditable($plan);
        $customer = $this->fetchOne(
            'SELECT ID FROM SFM_DAY_PLANS_DETAILS WHERE DAYPLAN_ID = :plan_id AND CUST_ID = :customer_id',
            ['plan_id' => $planId, 'customer_id' => $customerId]
        );
        if ($customer === null) {
            throw new InvalidArgumentException('The listed customer was not found in this day plan.');
        }
        $this->transaction(function () use ($planId, $customerId): void {
            $this->deleteListedCustomer($planId, $customerId);
        });
        return ['day_plan_id' => $planId, 'customer_id' => $customerId, 'deleted' => true];
    }

    public function addUnlistedCustomer(int $employeeId, string $loginId, array $input): array
    {
        $planId = $this->requiredId($input['day_plan_id'] ?? null, 'day_plan_id');
        $typeId = $this->requiredId($input['customer_type_id'] ?? null, 'customer_type_id');
        $name = trim((string) ($input['name'] ?? ''));
        $qualification = trim((string) ($input['qualification'] ?? ''));
        $phone = trim((string) ($input['phone'] ?? ''));
        $address = trim((string) ($input['place'] ?? ''));
        if ($name === '' || strlen($name) > 200) {
            throw new InvalidArgumentException('name is required and must be no longer than 200 characters.');
        }
        if (strlen($qualification) > 100 || strlen($phone) > 50 || strlen($address) > 50) {
            throw new InvalidArgumentException('Customer qualification, phone, and place have exceeded their allowed lengths.');
        }
        if ($this->fetchOne(
            'SELECT CTYP_ID FROM SFM_CUST_TYPES WHERE CTYP_ID = :type_id',
            ['type_id' => $typeId]
        ) === null) {
            throw new InvalidArgumentException('The selected customer type is not available.');
        }
        if ($qualification !== '' && $this->fetchOne(
            'SELECT QUA_DESC FROM SFM_QUALIFICATIONS WHERE QUA_DESC = :qualification',
            ['qualification' => $qualification]
        ) === null) {
            throw new InvalidArgumentException('The selected qualification is not available.');
        }

        $plan = $this->findOwnedPlan($employeeId, $planId);
        if ($plan === null) {
            throw new InvalidArgumentException('The day plan was not found.');
        }
        $this->assertEditable($plan);

        return $this->transaction(function () use (
            $employeeId,
            $loginId,
            $planId,
            $typeId,
            $name,
            $qualification,
            $phone,
            $address,
        ): array {
            $plan = $this->findOwnedPlan($employeeId, $planId, true);
            if ($plan === null) {
                throw new InvalidArgumentException('The day plan was not found.');
            }
            $this->assertEditable($plan);
            $id = $this->executeReturning(<<<'SQL'
                INSERT INTO SFM_DAY_PLANS_UNLISTED (
                    ID, DAYPLAN_ID, EMP_ID, DAY_DATE, CUST_TYPE, CUST_NAME,
                    QUALIFICATION, PHONE, ADDRESS, CUST_COUNT, TOTAL_POB, CHG_ON, CHG_BY
                ) VALUES (
                    NULL, :plan_id, :employee_id, TO_DATE(:day_date, 'YYYY-MM-DD'),
                    :customer_type_id, :name, :qualification, :phone, :address,
                    1, 0, SYSDATE, :login_id
                )
                RETURNING ID INTO :return_id
                SQL, [
                    'plan_id' => $planId,
                    'employee_id' => $employeeId,
                    'day_date' => (string) $plan['DAY_DATE_TEXT'],
                    'customer_type_id' => $typeId,
                    'name' => $name,
                    'qualification' => $qualification === '' ? null : $qualification,
                    'phone' => $phone,
                    'address' => $address,
                    'login_id' => $loginId,
                ], ':return_id');

            return ['unlisted_customer_id' => (int) $id];
        });
    }

    public function customerActivity(
        int $employeeId,
        int $planId,
        ?int $customerId,
        ?int $unlistedCustomerId,
        string $activity
    ): ?array {
        $activity = $this->activityType($activity, $unlistedCustomerId !== null);
        $plan = $this->findOwnedPlan($employeeId, $planId);
        if ($plan === null) {
            return null;
        }
        $subject = $this->findActivitySubject($planId, $customerId, $unlistedCustomerId);
        if ($subject === null) {
            return null;
        }
        if ($activity === 'availability' && (int) $subject['CUSTOMER_TYPE_ID'] !== 2) {
            throw new InvalidArgumentException('Availability is only available for the supported customer type.');
        }

        $table = $this->activityTable($activity, $subject['is_unlisted'], (string) $subject['OB_TYPE']);
        $quantityColumn = $activity === 'detailing' || $activity === 'availability' ? '' : ', QTY';
        $hasFreeQuantity = $activity === 'pob' &&
            ($subject['is_unlisted'] || (string) $subject['OB_TYPE'] === 'P');
        $freeQuantityColumn = $activity === 'pob'
            ? ($hasFreeQuantity ? ', FREE_QTY, RATE' : ', RATE')
            : '';
        $idColumn = $subject['is_unlisted'] ? 'UNLS_ID' : 'CUST_ID';
        $availabilityFilter = $activity === 'availability' ? ' AND EFFEC_TO IS NULL' : '';
        $rows = $this->fetchAll(
            "SELECT ITEM_ID$quantityColumn$freeQuantityColumn FROM $table " .
            "WHERE DAYPLAN_ID = :plan_id AND $idColumn = :customer_id$availabilityFilter ORDER BY ID",
            ['plan_id' => $planId, 'customer_id' => $subject['subject_id']]
        );
        $remarks = '';
        $orderTo = '';
        if (!$subject['is_unlisted'] && $activity === 'pob') {
            $detail = $this->fetchOne(<<<'SQL'
                SELECT REMARKS, ORDER_TO
                FROM SFM_DAY_PLANS_DETAILS
                WHERE DAYPLAN_ID = :plan_id AND CUST_ID = :customer_id
                SQL, ['plan_id' => $planId, 'customer_id' => $subject['subject_id']]);
            $remarks = (string) ($detail['REMARKS'] ?? '');
            $orderTo = (string) ($detail['ORDER_TO'] ?? '');
        }

        return [
            'activity' => $activity,
            'customer_type_id' => (int) $subject['CUSTOMER_TYPE_ID'],
            'order_type' => (string) ($subject['OB_TYPE'] ?? ''),
            'remarks' => $remarks,
            'order_to' => $orderTo,
            'catalogue' => $this->activityCatalogue(
                $activity,
                $employeeId,
                (int) $plan['DIVSN_ID'],
                (string) $plan['DAY_DATE_TEXT']
            ),
            'products' => array_map(static function (array $row): array {
                return [
                    'product_id' => (int) $row['ITEM_ID'],
                    'quantity' => isset($row['QTY']) ? (float) $row['QTY'] : 0,
                    'free_quantity' => isset($row['FREE_QTY']) ? (float) $row['FREE_QTY'] : 0,
                    'rate' => isset($row['RATE']) ? (float) $row['RATE'] : null,
                    'selected' => true,
                ];
            }, $rows),
        ];
    }

    public function saveCustomerActivity(int $employeeId, string $loginId, array $input): array
    {
        $planId = $this->requiredId($input['day_plan_id'] ?? null, 'day_plan_id');
        $customerId = $this->positiveId($input['customer_id'] ?? null);
        $unlistedCustomerId = $this->positiveId($input['unlisted_customer_id'] ?? null);
        if (($customerId === null) === ($unlistedCustomerId === null)) {
            throw new InvalidArgumentException('Provide exactly one of customer_id or unlisted_customer_id.');
        }
        $activity = $this->activityType(
            (string) ($input['activity'] ?? ''),
            $unlistedCustomerId !== null
        );
        $productsInput = $input['products'] ?? null;
        if (!is_array($productsInput)) {
            throw new InvalidArgumentException('products must be an array.');
        }
        $products = $this->normalizeActivityProducts($productsInput, $activity);

        return $this->transaction(function () use (
            $employeeId,
            $loginId,
            $planId,
            $customerId,
            $unlistedCustomerId,
            $activity,
            $products,
            $input
        ): array {
            $plan = $this->findOwnedPlan($employeeId, $planId, true);
            if ($plan === null) {
                throw new InvalidArgumentException('The day plan was not found.');
            }
            $this->assertEditable($plan);
            $subject = $this->findActivitySubject($planId, $customerId, $unlistedCustomerId);
            if ($subject === null) {
                throw new InvalidArgumentException('The selected customer is not part of this day plan.');
            }
            $activity = $this->activityType($activity, $subject['is_unlisted']);
            if ($activity === 'availability' && (int) $subject['CUSTOMER_TYPE_ID'] !== 2) {
                throw new InvalidArgumentException('Availability is only available for the supported customer type.');
            }
            $table = $this->activityTable($activity, $subject['is_unlisted'], (string) $subject['OB_TYPE']);
            $idColumn = $subject['is_unlisted'] ? 'UNLS_ID' : 'CUST_ID';
            $availabilityFilter = $activity === 'availability' ? ' AND EFFEC_TO IS NULL' : '';
            $this->execute(
                "DELETE FROM $table WHERE DAYPLAN_ID = :plan_id AND $idColumn = :customer_id$availabilityFilter",
                ['plan_id' => $planId, 'customer_id' => $subject['subject_id']]
            );

            $totalPob = 0.0;
            foreach ($products as $product) {
                $catalogue = $this->activityProduct(
                    (int) $product['product_id'],
                    (int) $plan['DIVSN_ID'],
                    (string) $plan['DAY_DATE_TEXT'],
                    $activity,
                    $employeeId
                );
                if ($catalogue === null) {
                    throw new InvalidArgumentException('A selected product is not available for this division and date.');
                }
                $this->insertActivityProduct(
                    $table,
                    $idColumn,
                    $employeeId,
                    $loginId,
                    $planId,
                    (string) $plan['DAY_DATE_TEXT'],
                    (int) $subject['subject_id'],
                    $subject,
                    (int) $plan['DIVSN_ID'],
                    $activity,
                    $product,
                    (float) $catalogue['RATE']
                );
                if ($activity === 'pob') {
                    $totalPob += (float) $product['quantity'] * (float) $catalogue['RATE'];
                }
            }

            if ($activity === 'pob') {
                if ($subject['is_unlisted']) {
                    $this->execute(<<<'SQL'
                        UPDATE SFM_DAY_PLANS_UNLISTED
                        SET TOTAL_POB = :total_pob
                        WHERE DAYPLAN_ID = :plan_id AND ID = :customer_id
                        SQL, [
                            'total_pob' => $totalPob,
                            'plan_id' => $planId,
                            'customer_id' => (int) $subject['subject_id'],
                        ]);
                } else {
                    $remarks = trim((string) ($input['remarks'] ?? ''));
                    $orderTo = trim((string) ($input['order_to'] ?? ''));
                    if (strlen($remarks) > 500 || strlen($orderTo) > 100) {
                        throw new InvalidArgumentException('POB remarks or order-to value exceeds the allowed length.');
                    }
                    $this->execute(<<<'SQL'
                        UPDATE SFM_DAY_PLANS_DETAILS
                        SET TOTAL_POB = :total_pob, REMARKS = :remarks, ORDER_TO = :order_to
                        WHERE DAYPLAN_ID = :plan_id AND CUST_ID = :customer_id
                        SQL, [
                            'total_pob' => $totalPob,
                            'remarks' => $remarks,
                            'order_to' => $orderTo,
                            'plan_id' => $planId,
                            'customer_id' => (int) $subject['subject_id'],
                        ]);
                }
            }

            return [
                'day_plan_id' => $planId,
                'customer_id' => $customerId,
                'unlisted_customer_id' => $unlistedCustomerId,
                'activity' => $activity,
                'total_pob' => $totalPob,
                'saved_product_count' => count($products),
            ];
        });
    }

    public function submit(int $employeeId, string $loginId, int $planId): array
    {
        return $this->transaction(function () use ($employeeId, $loginId, $planId): array {
            $plan = $this->findOwnedPlan($employeeId, $planId, true);
            if ($plan === null) {
                throw new InvalidArgumentException('The day plan was not found.');
            }
            if ($plan['STATUS'] === 'T') {
                return ['day_plan_id' => $planId, 'status' => 'T'];
            }
            $this->assertEditable($plan);

            $counts = $this->fetchOne(<<<'SQL'
                SELECT
                    (SELECT COUNT(1) FROM SFM_DAY_PLANS_DETAILS WHERE DAYPLAN_ID = :plan_id) AS LISTED_COUNT,
                    (SELECT COUNT(1) FROM SFM_DAY_PLANS_UNLISTED WHERE DAYPLAN_ID = :plan_id) AS UNLISTED_COUNT
                FROM DUAL
                SQL, ['plan_id' => $planId]);
            if ((int) ($counts['LISTED_COUNT'] ?? 0) + (int) ($counts['UNLISTED_COUNT'] ?? 0) === 0) {
                throw new InvalidArgumentException('Add at least one customer before submitting the day plan.');
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

            $this->execute(<<<'SQL'
                UPDATE SFM_DAY_PLANS
                SET STATUS = 'T',
                    LISTED_COUNT = :listed_count,
                    UNLISTED_COUNT = :unlisted_count,
                    CHG_ON = SYSDATE,
                    CHG_BY = :login_id
                WHERE ID = :plan_id AND EMP_ID = :employee_id AND STATUS <> 'A'
                SQL, [
                    'listed_count' => (int) $counts['LISTED_COUNT'],
                    'unlisted_count' => (int) $counts['UNLISTED_COUNT'],
                    'login_id' => $loginId,
                    'plan_id' => $planId,
                    'employee_id' => $employeeId,
                ]);

            $taskExists = $this->fetchOne(<<<'SQL'
                SELECT ID FROM SFM_USER_TASKS
                WHERE TASK_GROUP = 'DayPlanAuth'
                  AND TRAN_ID = :plan_id
                  AND EMP_ID = :employee_id
                  AND STATUS = 'O'
                FETCH FIRST 1 ROWS ONLY
                SQL, ['plan_id' => $planId, 'employee_id' => $employeeId]);
            if ($taskExists === null) {
                $dayLabel = (string) ($plan['DAY_DATE_LABEL'] ?? $plan['DAY_DATE_TEXT']);
                $this->execute(<<<'SQL'
                    INSERT INTO SFM_USER_TASKS (
                        ID, TASK_DATE, EXPIRY_DATE, TASK_GROUP, TRAN_ID, TRAN_DESC,
                        EMP_ID, EMP_ID_FOR, STATUS, AUTH_ON, AUTH_BY, REMARKS
                    ) VALUES (
                        NULL, SYSDATE, SYSDATE + 2, 'DayPlanAuth', :plan_id,
                        :description, :employee_id, :manager_id, 'O', NULL, NULL, NULL
                    )
                    SQL, [
                        'plan_id' => $planId,
                        'description' => 'Daily Reporting Dtd : ' . $dayLabel,
                        'employee_id' => $employeeId,
                        'manager_id' => (int) $manager['MANAGER_ID'],
                    ]);
            }
            return ['day_plan_id' => $planId, 'status' => 'T'];
        });
    }

    private function listedCustomers(int $planId): array
    {
        $rows = $this->fetchAll(<<<'SQL'
            SELECT d.CUST_ID AS CUSTOMER_ID, INITCAP(c.CUST_NAME) AS CUSTOMER_NAME,
                   d.ROUTE_ID, d.TOTAL_POB, d.REMARKS,
                   get_custid_custtypeid(d.CUST_ID, d.HQ_ID, p.DIVSN_ID) AS CUSTOMER_TYPE_ID,
                   ct.CTYP_DESC AS CUSTOMER_TYPE, ct.OB_TYPE
            FROM SFM_DAY_PLANS_DETAILS d
            INNER JOIN SFM_DAY_PLANS p ON p.ID = d.DAYPLAN_ID
            INNER JOIN SFM_CUSTOMER_INFO c ON c.ID = d.CUST_ID
            LEFT JOIN SFM_CUST_TYPES ct
                ON ct.CTYP_ID = get_custid_custtypeid(d.CUST_ID, d.HQ_ID, p.DIVSN_ID)
            WHERE d.DAYPLAN_ID = :plan_id
            ORDER BY d.ID
            SQL, ['plan_id' => $planId]);
        return array_map(static fn (array $row): array => [
            'customer_id' => (int) $row['CUSTOMER_ID'],
            'name' => (string) $row['CUSTOMER_NAME'],
            'route_id' => (int) $row['ROUTE_ID'],
            'customer_type_id' => $row['CUSTOMER_TYPE_ID'] === null
                ? null
                : (int) $row['CUSTOMER_TYPE_ID'],
            'customer_type' => (string) ($row['CUSTOMER_TYPE'] ?? ''),
            'order_type' => (string) ($row['OB_TYPE'] ?? ''),
            'total_pob' => (float) ($row['TOTAL_POB'] ?? 0),
            'remarks' => (string) ($row['REMARKS'] ?? ''),
        ], $rows);
    }

    private function unlistedCustomers(int $planId): array
    {
        $rows = $this->fetchAll(<<<'SQL'
            SELECT u.ID, u.CUST_TYPE, u.CUST_NAME, u.QUALIFICATION, u.PHONE,
                   u.ADDRESS, u.TOTAL_POB, ct.CTYP_DESC, ct.OB_TYPE
            FROM SFM_DAY_PLANS_UNLISTED u
            LEFT JOIN SFM_CUST_TYPES ct ON ct.CTYP_ID = u.CUST_TYPE
            WHERE u.DAYPLAN_ID = :plan_id
            ORDER BY u.ID
            SQL, ['plan_id' => $planId]);
        return array_map(static fn (array $row): array => [
            'unlisted_customer_id' => (int) $row['ID'],
            'customer_type_id' => (int) $row['CUST_TYPE'],
            'customer_type' => (string) ($row['CTYP_DESC'] ?? ''),
            'order_type' => (string) ($row['OB_TYPE'] ?? ''),
            'name' => (string) $row['CUST_NAME'],
            'qualification' => (string) ($row['QUALIFICATION'] ?? ''),
            'phone' => (string) ($row['PHONE'] ?? ''),
            'place' => (string) ($row['ADDRESS'] ?? ''),
            'total_pob' => (float) ($row['TOTAL_POB'] ?? 0),
        ], $rows);
    }

    private function findPlan(int $employeeId, string $date, bool $forUpdate = false): ?array
    {
        return $this->fetchOne(<<<SQL
            SELECT ID, EMP_ID, DAY_DATE, DIVSN_ID, START_TOWN, REMARK, STATUS,
                   TO_CHAR(DAY_DATE, 'YYYY-MM-DD') AS DAY_DATE_TEXT,
                   TO_CHAR(DAY_DATE, 'DD-Mon-YYYY') AS DAY_DATE_LABEL
            FROM SFM_DAY_PLANS
            WHERE EMP_ID = :employee_id
              AND TRUNC(DAY_DATE) = TO_DATE(:day_date, 'YYYY-MM-DD')
            {$this->lockClause($forUpdate)}
            SQL, ['employee_id' => $employeeId, 'day_date' => $date]);
    }

    private function findOwnedPlan(int $employeeId, int $planId, bool $forUpdate = false): ?array
    {
        return $this->fetchOne(<<<SQL
            SELECT ID, EMP_ID, DAY_DATE, DIVSN_ID, START_TOWN, REMARK, STATUS,
                   TO_CHAR(DAY_DATE, 'YYYY-MM-DD') AS DAY_DATE_TEXT,
                   TO_CHAR(DAY_DATE, 'DD-Mon-YYYY') AS DAY_DATE_LABEL
            FROM SFM_DAY_PLANS
            WHERE ID = :plan_id AND EMP_ID = :employee_id
            {$this->lockClause($forUpdate)}
            SQL, ['plan_id' => $planId, 'employee_id' => $employeeId]);
    }

    private function mapPlan(array $row): array
    {
        return [
            'day_plan_id' => (int) $row['ID'],
            'date' => (string) $row['DAY_DATE_TEXT'],
            'date_label' => (string) $row['DAY_DATE_LABEL'],
            'start_town_id' => $row['START_TOWN'] === null ? null : (int) $row['START_TOWN'],
            'remarks' => (string) ($row['REMARK'] ?? ''),
            'status' => (string) $row['STATUS'],
            'can_edit' => !in_array((string) $row['STATUS'], ['A', 'T'], true),
        ];
    }

    private function assertEditable(array $plan): void
    {
        if (in_array((string) $plan['STATUS'], ['A', 'T'], true)) {
            throw new InvalidArgumentException('A submitted or confirmed day plan cannot be changed.');
        }
    }

    private function date(string $value): string
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', trim($value));
        if ($date === false || $date->format('Y-m-d') !== trim($value)) {
            throw new InvalidArgumentException('date must use YYYY-MM-DD format.');
        }
        return $date->format('Y-m-d');
    }

    private function requiredId(mixed $value, string $field): int
    {
        $id = $this->positiveId($value);
        if ($id === null) {
            throw new InvalidArgumentException($field . ' must be a positive integer.');
        }
        return $id;
    }

    private function positiveId(mixed $value): ?int
    {
        if (is_int($value) && $value > 0) {
            return $value;
        }
        if (is_string($value) && ctype_digit($value) && (int) $value > 0) {
            return (int) $value;
        }
        return null;
    }

    private function lockClause(bool $forUpdate): string
    {
        return $forUpdate ? 'FOR UPDATE' : '';
    }

    private function activityType(string $activity, bool $isUnlisted): string
    {
        $activity = strtolower(trim($activity));
        $allowed = $isUnlisted
            ? ['detailing', 'sample', 'pob']
            : ['detailing', 'sample', 'pob', 'availability'];
        if (!in_array($activity, $allowed, true)) {
            throw new InvalidArgumentException('The requested customer activity is not available.');
        }
        return $activity;
    }

    private function findActivitySubject(
        int $planId,
        ?int $customerId,
        ?int $unlistedCustomerId
    ): ?array {
        if ($customerId !== null) {
            $row = $this->fetchOne(<<<SQL
                SELECT d.CUST_ID AS SUBJECT_ID,
                       get_custid_custtypeid(d.CUST_ID, d.HQ_ID, p.DIVSN_ID) AS CUSTOMER_TYPE_ID,
                       ct.OB_TYPE,
                       0 AS IS_UNLISTED
                FROM SFM_DAY_PLANS_DETAILS d
                INNER JOIN SFM_DAY_PLANS p ON p.ID = d.DAYPLAN_ID
                LEFT JOIN SFM_CUST_TYPES ct
                    ON ct.CTYP_ID = get_custid_custtypeid(d.CUST_ID, d.HQ_ID, p.DIVSN_ID)
                WHERE d.DAYPLAN_ID = :plan_id AND d.CUST_ID = :customer_id
                SQL, ['plan_id' => $planId, 'customer_id' => $customerId]);
        } else {
            $row = $this->fetchOne(<<<SQL
                SELECT u.ID AS SUBJECT_ID, u.CUST_TYPE AS CUSTOMER_TYPE_ID,
                       ct.OB_TYPE, 1 AS IS_UNLISTED
                FROM SFM_DAY_PLANS_UNLISTED u
                LEFT JOIN SFM_CUST_TYPES ct ON ct.CTYP_ID = u.CUST_TYPE
                WHERE u.DAYPLAN_ID = :plan_id AND u.ID = :customer_id
                SQL, ['plan_id' => $planId, 'customer_id' => $unlistedCustomerId]);
        }
        if ($row === null) {
            return null;
        }
        return [
            'subject_id' => (int) $row['SUBJECT_ID'],
            'CUSTOMER_TYPE_ID' => (int) $row['CUSTOMER_TYPE_ID'],
            'OB_TYPE' => (string) ($row['OB_TYPE'] ?? ''),
            'is_unlisted' => (int) $row['IS_UNLISTED'] === 1,
        ];
    }

    private function activityTable(string $activity, bool $isUnlisted, string $orderType): string
    {
        if ($activity === 'detailing') {
            return $isUnlisted ? 'SFM_DAY_PLANS_UNLSDECR' : 'SFM_DAY_PLANS_PRDDECR';
        }
        if ($activity === 'sample') {
            return $isUnlisted ? 'SFM_DAY_PLANS_UNLSSMPL' : 'SFM_DAY_PLANS_PRDSMPL';
        }
        if ($activity === 'availability') {
            return 'SFM_DAY_PLANS_PRDAVLB';
        }
        if ($isUnlisted) {
            return 'SFM_DAY_PLANS_UNLSPOB';
        }
        if ($orderType === 'P') {
            return 'SFM_DAY_PLANS_CUSTPOB';
        }
        if ($orderType === 'S') {
            return 'SFM_DAY_PLANS_CUSTSOB';
        }
        throw new InvalidArgumentException('The customer order type is not configured.');
    }

    private function normalizeActivityProducts(array $items, string $activity): array
    {
        $products = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                throw new InvalidArgumentException('Each product must be an object.');
            }
            $productId = $this->requiredId($item['product_id'] ?? null, 'product_id');
            $quantity = $this->number($item['quantity'] ?? 0, 'quantity');
            $freeQuantity = $this->number($item['free_quantity'] ?? 0, 'free_quantity');
            if (in_array($activity, ['sample', 'pob'], true) && $quantity <= 0) {
                continue;
            }
            if ($freeQuantity < 0 || ($activity === 'pob' && $freeQuantity > 100000)) {
                throw new InvalidArgumentException('free_quantity must be between 0 and 100000.');
            }
            $products[$productId] = [
                'product_id' => $productId,
                'quantity' => $quantity,
                'free_quantity' => $freeQuantity,
            ];
        }
        return array_values($products);
    }

    private function activityProduct(
        int $productId,
        int $divisionId,
        string $date,
        string $activity,
        int $employeeId
    ): ?array
    {
        if ($activity === 'detailing' || $activity === 'sample') {
            $itemType = $activity === 'detailing' ? 'Detailing Product' : 'Free Distribution';
            $balanceFilter = $activity === 'sample'
                ? " AND GET_ITEM_BALANCE(:employee_id, i.ID, TO_DATE(:day_date, 'YYYY-MM-DD')) > 0"
                : '';
            $params = [
                'division_id' => $divisionId,
                'day_date' => $date,
                'product_id' => $productId,
            ];
            if ($activity === 'sample') {
                $params['employee_id'] = $employeeId;
            }
            return $this->fetchOne(<<<SQL
                SELECT i.ID, 0 AS RATE
                FROM SFM_ITEM_MASTER i
                INNER JOIN SFM_ITEM_DIVISIONS d
                    ON d.ITEM_ID = i.ID AND d.DIVSN_ID = :division_id
                   AND TO_DATE(:day_date, 'YYYY-MM-DD') BETWEEN TRUNC(d.EFFEC_FROM)
                       AND NVL(TRUNC(d.EFFEC_TO), DATE '3000-01-01')
                INNER JOIN SFM_ITEM_GROUP_MAP m
                    ON m.ITEM_ID = i.ID
                   AND TO_DATE(:day_date, 'YYYY-MM-DD') BETWEEN TRUNC(m.EFFEC_FROM)
                       AND NVL(TRUNC(m.EFFEC_TO), DATE '3000-01-01')
                INNER JOIN SFM_ITEM_GROUPS g ON g.GRP_ID = m.GRP_ID
                WHERE i.ID = :product_id
                  AND i.STATUS = 'A'
                  AND i.ITEM_TYPE = '$itemType'
                  $balanceFilter
                FETCH FIRST 1 ROWS ONLY
                SQL, $params);
        }

        return $this->fetchOne(<<<'SQL'
            SELECT i.ID, r.RATE
            FROM SFM_ITEM_MASTER i
            INNER JOIN SFM_ITEM_DIVISIONS d
                ON d.ITEM_ID = i.ID AND d.DIVSN_ID = :division_id
               AND TO_DATE(:day_date, 'YYYY-MM-DD') BETWEEN TRUNC(d.EFFEC_FROM)
                   AND NVL(TRUNC(d.EFFEC_TO), DATE '3000-01-01')
            INNER JOIN SFM_ITEM_RATES r
                ON r.ITEM_ID = i.ID AND r.DIVSN_ID = d.DIVSN_ID
               AND TO_DATE(:day_date, 'YYYY-MM-DD') BETWEEN TRUNC(r.EFFEC_FROM)
                   AND NVL(TRUNC(r.EFFEC_TO), DATE '3000-01-01')
            WHERE i.ID = :product_id
              AND i.STATUS = 'A'
              AND i.ITEM_TYPE = 'Sellable Product'
            FETCH FIRST 1 ROWS ONLY
            SQL, [
                'division_id' => $divisionId,
                'day_date' => $date,
                'product_id' => $productId,
            ]);
    }

    private function activityCatalogue(
        string $activity,
        int $employeeId,
        int $divisionId,
        string $date
    ): array
    {
        if ($activity === 'detailing' || $activity === 'sample') {
            $itemType = $activity === 'detailing' ? 'Detailing Product' : 'Free Distribution';
            $balanceFilter = $activity === 'sample'
                ? " AND GET_ITEM_BALANCE(:employee_id, i.ID, TO_DATE(:day_date, 'YYYY-MM-DD')) > 0"
                : '';
            $params = [
                'division_id' => $divisionId,
                'day_date' => $date,
            ];
            if ($activity === 'sample') {
                $params['employee_id'] = $employeeId;
            }
            $rows = $this->fetchAll(<<<SQL
                SELECT DISTINCT i.ID AS PRODUCT_ID, i.DESCR AS PRODUCT_NAME,
                       g.GRP_ID AS CATEGORY_ID, g.GRP_DESC AS CATEGORY_NAME,
                       g.SEQ AS CATEGORY_SEQUENCE, 0 AS RATE
                FROM SFM_ITEM_MASTER i
                INNER JOIN SFM_ITEM_DIVISIONS d
                    ON d.ITEM_ID = i.ID AND d.DIVSN_ID = :division_id
                   AND TO_DATE(:day_date, 'YYYY-MM-DD') BETWEEN TRUNC(d.EFFEC_FROM)
                       AND NVL(TRUNC(d.EFFEC_TO), DATE '3000-01-01')
                INNER JOIN SFM_ITEM_GROUP_MAP m
                    ON m.ITEM_ID = i.ID
                   AND TO_DATE(:day_date, 'YYYY-MM-DD') BETWEEN TRUNC(m.EFFEC_FROM)
                       AND NVL(TRUNC(m.EFFEC_TO), DATE '3000-01-01')
                INNER JOIN SFM_ITEM_GROUPS g ON g.GRP_ID = m.GRP_ID
                WHERE i.ITEM_TYPE = '$itemType'
                  AND i.STATUS = 'A'
                  $balanceFilter
                ORDER BY g.SEQ, g.GRP_DESC, i.DESCR
                SQL, $params);
        } else {
            $rows = $this->fetchAll(<<<'SQL'
            SELECT DISTINCT i.ID AS PRODUCT_ID, i.DESCR AS PRODUCT_NAME,
                   g.GRP_ID AS CATEGORY_ID, g.GRP_DESC AS CATEGORY_NAME,
                   g.SEQ AS CATEGORY_SEQUENCE, r.RATE
            FROM SFM_ITEM_MASTER i
            INNER JOIN SFM_ITEM_DIVISIONS d
                ON d.ITEM_ID = i.ID AND d.DIVSN_ID = :division_id
               AND TO_DATE(:day_date, 'YYYY-MM-DD') BETWEEN TRUNC(d.EFFEC_FROM)
                   AND NVL(TRUNC(d.EFFEC_TO), DATE '3000-01-01')
            INNER JOIN SFM_ITEM_RATES r
                ON r.ITEM_ID = i.ID AND r.DIVSN_ID = d.DIVSN_ID
               AND TO_DATE(:day_date, 'YYYY-MM-DD') BETWEEN TRUNC(r.EFFEC_FROM)
                   AND NVL(TRUNC(r.EFFEC_TO), DATE '3000-01-01')
            INNER JOIN SFM_ITEM_GROUP_MAP m
                ON m.ITEM_ID = i.ID
               AND TO_DATE(:day_date, 'YYYY-MM-DD') BETWEEN TRUNC(m.EFFEC_FROM)
                   AND NVL(TRUNC(m.EFFEC_TO), DATE '3000-01-01')
            INNER JOIN SFM_ITEM_GROUPS g ON g.GRP_ID = m.GRP_ID
            WHERE i.ITEM_TYPE = 'Sellable Product'
              AND i.STATUS = 'A'
            ORDER BY g.SEQ, g.GRP_DESC, i.DESCR, r.RATE
            SQL, ['division_id' => $divisionId, 'day_date' => $date]);
        }
        $categories = [];
        foreach ($rows as $row) {
            $categoryId = (int) $row['CATEGORY_ID'];
            $key = (string) $categoryId;
            if (!isset($categories[$key])) {
                $categories[$key] = [
                    'category_id' => $categoryId,
                    'name' => (string) $row['CATEGORY_NAME'],
                    'products' => [],
                ];
            }
            $categories[$key]['products'][] = [
                'product_id' => (int) $row['PRODUCT_ID'],
                'name' => (string) $row['PRODUCT_NAME'],
                'rate' => (float) $row['RATE'],
            ];
        }
        return array_values($categories);
    }

    private function insertActivityProduct(
        string $table,
        string $idColumn,
        int $employeeId,
        string $loginId,
        int $planId,
        string $date,
        int $subjectId,
        array $subject,
        int $divisionId,
        string $activity,
        array $product,
        float $rate
    ): void {
        $params = [
            'plan_id' => $planId,
            'employee_id' => $employeeId,
            'day_date' => $date,
            'customer_id' => $subjectId,
            'item_id' => (int) $product['product_id'],
            'login_id' => $loginId,
        ];
        if ($activity === 'detailing') {
            if ($subject['is_unlisted']) {
                $this->execute(<<<'SQL'
                    INSERT INTO SFM_DAY_PLANS_UNLSDECR (
                        ID, DAYPLAN_ID, EMP_ID, DAY_DATE, UNLS_ID, ITEM_ID, CHG_ON, CHG_BY
                    ) VALUES (
                        NULL, :plan_id, :employee_id, TO_DATE(:day_date, 'YYYY-MM-DD'),
                        :customer_id, :item_id, SYSDATE, :login_id
                    )
                    SQL, $params);
                return;
            }
            $params['division_id'] = $divisionId;
            $this->execute(<<<SQL
                INSERT INTO $table (
                    ID, DAYPLAN_ID, EMP_ID, DAY_DATE, CUST_ID, ITEM_ID, STG_ID, CHG_ON, CHG_BY
                ) VALUES (
                    NULL, :plan_id, :employee_id, TO_DATE(:day_date, 'YYYY-MM-DD'),
                    :customer_id, :item_id,
                    GET_CUST_ITEM_STGID(:employee_id, :customer_id, :item_id, TO_DATE(:day_date, 'YYYY-MM-DD'), :division_id),
                    SYSDATE, :login_id
                )
                SQL, $params);
            return;
        }
        if ($activity === 'availability') {
            $this->execute(<<<'SQL'
                INSERT INTO SFM_DAY_PLANS_PRDAVLB (
                    ID, DAYPLAN_ID, EMP_ID, DAY_DATE, CUST_ID, ITEM_ID, EFFEC_FROM, CHG_ON, CHG_BY
                ) VALUES (
                    NULL, :plan_id, :employee_id, TO_DATE(:day_date, 'YYYY-MM-DD'),
                    :customer_id, :item_id, TO_DATE(:day_date, 'YYYY-MM-DD'), SYSDATE, :login_id
                )
                SQL, $params);
            return;
        }
        if ($activity === 'sample') {
            $quantity = (float) $product['quantity'];
            $params['quantity'] = $quantity;
            $fields = $subject['is_unlisted'] ? '' : ', STG_ID';
            $values = $subject['is_unlisted']
                ? ''
                : ", GET_CUST_ITEM_STGID(:employee_id, :customer_id, :item_id, TO_DATE(:day_date, 'YYYY-MM-DD'), :division_id)";
            if (!$subject['is_unlisted']) {
                $params['division_id'] = $divisionId;
            }
            $this->execute(<<<SQL
                INSERT INTO $table (
                    ID, DAYPLAN_ID, EMP_ID, DAY_DATE, {$idColumn}, ITEM_ID, QTY$fields, CHG_ON, CHG_BY
                ) VALUES (
                    NULL, :plan_id, :employee_id, TO_DATE(:day_date, 'YYYY-MM-DD'),
                    :customer_id, :item_id, :quantity$values, SYSDATE, :login_id
                )
                SQL, $params);
            return;
        }

        $quantity = (float) $product['quantity'];
        $freeQuantity = (float) $product['free_quantity'];
        $amount = $quantity * $rate;
        $params['quantity'] = $quantity;
        $params['rate'] = $rate;
        $params['amount'] = $amount;
        if ($subject['is_unlisted']) {
            $this->execute(<<<'SQL'
                INSERT INTO SFM_DAY_PLANS_UNLSPOB (
                    ID, DAYPLAN_ID, EMP_ID, DAY_DATE, UNLS_ID, ITEM_ID,
                    QTY, FREE_QTY, RATE, POBAMT, CHG_ON, CHG_BY
                ) VALUES (
                    NULL, :plan_id, :employee_id, TO_DATE(:day_date, 'YYYY-MM-DD'),
                    :customer_id, :item_id, :quantity, :free_quantity, :rate,
                    :amount, SYSDATE, :login_id
                )
                SQL, $params);
            return;
        }
        $params['division_id'] = $divisionId;
        if ($subject['OB_TYPE'] === 'P') {
            $params['free_quantity'] = $freeQuantity;
        }
        $freeQuantityField = $subject['OB_TYPE'] === 'P' ? 'FREE_QTY,' : '';
        $freeQuantityValue = $subject['OB_TYPE'] === 'P' ? ':free_quantity,' : '';
        $this->execute(<<<SQL
            INSERT INTO $table (
                ID, DAYPLAN_ID, EMP_ID, DAY_DATE, CUST_ID, ITEM_ID, QTY,
                $freeQuantityField
                RATE, POBAMT, STG_ID, CHG_ON, CHG_BY
            ) VALUES (
                NULL, :plan_id, :employee_id, TO_DATE(:day_date, 'YYYY-MM-DD'),
                :customer_id, :item_id, :quantity,
                $freeQuantityValue
                :rate, :amount,
                GET_CUST_ITEM_STGID(:employee_id, :customer_id, :item_id, TO_DATE(:day_date, 'YYYY-MM-DD'), :division_id),
                SYSDATE, :login_id
            )
            SQL, $params);
    }

    private function number(mixed $value, string $field): float
    {
        if (!is_numeric($value)) {
            throw new InvalidArgumentException($field . ' must be a number.');
        }
        $number = (float) $value;
        if (!is_finite($number) || $number < 0 || $number > 100000) {
            throw new InvalidArgumentException($field . ' must be between 0 and 100000.');
        }
        return $number;
    }

    private function deleteListedCustomer(int $planId, int $customerId): void
    {
        foreach ([
            'SFM_DAY_PLANS_PRDDECR',
            'SFM_DAY_PLANS_PRDSMPL',
            'SFM_DAY_PLANS_CUSTPOB',
            'SFM_DAY_PLANS_CUSTSOB',
            'SFM_DAY_PLANS_PRDAVLB',
            'SFM_DAY_PLANS_JTEMP',
        ] as $table) {
            $this->execute(
                "DELETE FROM $table WHERE DAYPLAN_ID = :plan_id AND CUST_ID = :customer_id",
                ['plan_id' => $planId, 'customer_id' => $customerId]
            );
        }
        $this->execute(
            'DELETE FROM SFM_DAY_PLANS_DETAILS WHERE DAYPLAN_ID = :plan_id AND CUST_ID = :customer_id',
            ['plan_id' => $planId, 'customer_id' => $customerId]
        );
    }
}
