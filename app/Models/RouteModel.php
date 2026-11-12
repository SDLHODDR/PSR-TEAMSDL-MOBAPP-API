<?php

declare(strict_types=1);

namespace App\Models;

use InvalidArgumentException;
use SDL\Framework\Database;
use SDL\Framework\Model;

final class RouteModel extends Model
{
    private const REQUIRED_OWNER_EXEMPT_TYPES = [8, 9, 23, 24, 25, 27];

    public function __construct(Database $db)
    {
        parent::__construct($db);
    }

    public function routes(int $employeeId): array
    {
        $scope = $this->employeeScope($employeeId);
        $rows = $this->fetchAll(<<<'SQL'
            SELECT r.ROUTE_SEQ AS ROUTE_ID,
                   INITCAP(GET_TOWN_DESC(r.TOWN_ID)) AS TOWN,
                   COUNT(DISTINCT d.CUST_ID) AS CUSTOMER_COUNT,
                   COUNT(DISTINCT CASE WHEN ch.CONSENT_FLAG = 'Y' THEN d.CUST_ID END) AS VERIFIED_COUNT
            FROM SFM_ROUTE_PLANS r
            INNER JOIN SFM_ROUTE_PLANS_DET d ON d.ROUTE_ID = r.ID
            INNER JOIN SFM_CUSTOMER_INFO c ON c.ID = d.CUST_ID
            LEFT JOIN SFM_CUST_HQ ch
                ON ch.CUST_ID = d.CUST_ID
               AND ch.HQ_ID = :hq_id
               AND ch.DIVSN_ID = :division_id
            WHERE r.STATUS = 'A'
              AND d.STATUS = 'A'
              AND r.HQ_ID = :hq_id
              AND r.DIVSN_ID = :division_id
            GROUP BY r.ROUTE_SEQ, r.TOWN_ID
            ORDER BY r.ROUTE_SEQ
            SQL, ['hq_id' => $scope['hq_id'], 'division_id' => $scope['division_id']]);

        return [
            'routes' => array_map(static fn (array $row): array => [
                'route_id' => (int) $row['ROUTE_ID'],
                'town' => (string) $row['TOWN'],
                'customer_count' => (int) $row['CUSTOMER_COUNT'],
                'verified_count' => (int) $row['VERIFIED_COUNT'],
                'unverified_count' => (int) $row['CUSTOMER_COUNT'] - (int) $row['VERIFIED_COUNT'],
            ], $rows),
        ];
    }

    public function customers(int $employeeId, int $routeId): ?array
    {
        $scope = $this->employeeScope($employeeId);
        $route = $this->findRoute($routeId, $scope);
        if ($route === null) {
            return null;
        }

        $rows = $this->fetchAll(<<<'SQL'
            SELECT d.CUST_ID AS CUSTOMER_ID,
                   INITCAP(c.CUST_NAME) AS CUSTOMER_NAME,
                   ct.CTYP_DESC AS CUSTOMER_TYPE,
                   get_custid_custtypeid(d.CUST_ID, r.HQ_ID, r.DIVSN_ID) AS CUSTOMER_TYPE_ID,
                   NVL(ch.CONSENT_FLAG, 'N') AS CONSENT_FLAG,
                   TO_CHAR(ch.VERIFY_ON, 'DD-Mon-YY HH:MI AM') AS VERIFIED_ON,
                   NVL(ch.PRI_CELL_NUM, c.CELL) AS PRIMARY_PHONE,
                   (SELECT CASE WHEN COUNT(1) > 0 THEN 'Y' ELSE 'N' END
                      FROM SFM_OUTBOX_OTP o
                     WHERE o.CUST_ID = d.CUST_ID
                       AND o.EMP_ID = :employee_id
                       AND o.OTP_FLAG = 'Consent'
                       AND o.STATUS = 'N') AS OTP_PENDING
            FROM SFM_ROUTE_PLANS r
            INNER JOIN SFM_ROUTE_PLANS_DET d ON d.ROUTE_ID = r.ID
            INNER JOIN SFM_CUSTOMER_INFO c ON c.ID = d.CUST_ID
            LEFT JOIN SFM_CUST_HQ ch
                ON ch.CUST_ID = d.CUST_ID
               AND ch.HQ_ID = r.HQ_ID
               AND ch.DIVSN_ID = r.DIVSN_ID
            LEFT JOIN SFM_CUST_TYPES ct
                ON ct.CTYP_ID = get_custid_custtypeid(d.CUST_ID, r.HQ_ID, r.DIVSN_ID)
            WHERE r.ROUTE_SEQ = :route_id
              AND r.HQ_ID = :hq_id
              AND r.DIVSN_ID = :division_id
              AND r.STATUS = 'A'
              AND d.STATUS = 'A'
            ORDER BY get_custid_custtypesq(d.CUST_ID, r.HQ_ID, r.DIVSN_ID),
                     INITCAP(c.CUST_NAME)
            SQL, [
                'employee_id' => $employeeId,
                'route_id' => $routeId,
                'hq_id' => $scope['hq_id'],
                'division_id' => $scope['division_id'],
            ]);

        return [
            'route' => $route,
            'customers' => array_map(static fn (array $row): array => [
                'customer_id' => (int) $row['CUSTOMER_ID'],
                'name' => (string) $row['CUSTOMER_NAME'],
                'customer_type' => (string) ($row['CUSTOMER_TYPE'] ?? ''),
                'customer_type_id' => $row['CUSTOMER_TYPE_ID'] === null
                    ? null
                    : (int) $row['CUSTOMER_TYPE_ID'],
                'verified' => $row['CONSENT_FLAG'] === 'Y',
                'verified_on' => $row['VERIFIED_ON'],
                'primary_phone' => $row['PRIMARY_PHONE'],
                'otp_pending' => $row['OTP_PENDING'] === 'Y',
            ], $rows),
        ];
    }

    public function customer(int $employeeId, int $routeId, int $customerId): ?array
    {
        $scope = $this->employeeScope($employeeId);
        $row = $this->fetchOne(<<<'SQL'
            SELECT d.CUST_ID AS CUSTOMER_ID,
                   INITCAP(c.CUST_NAME) AS CUSTOMER_NAME,
                   get_custid_custtypeid(d.CUST_ID, r.HQ_ID, r.DIVSN_ID) AS CUSTOMER_TYPE_ID,
                   ct.CTYP_DESC AS CUSTOMER_TYPE,
                   c.ADDRESS, c.STATE, c.CITY, c.PINCODE, c.LANDMARK, c.LOC_URL,
                   LOWER(c.PER_EMAIL) AS PERSONAL_EMAIL,
                   LOWER(c.BUS_EMAIL) AS BUSINESS_EMAIL,
                   NVL(ch.PRI_CELL_NUM, c.CELL) AS PRIMARY_PHONE,
                   ch.SEC_CELL_NUM AS ADDITIONAL_PHONE,
                   ch.OWNER_NAME,
                   NVL(ch.CONSENT_FLAG, 'N') AS CONSENT_FLAG,
                   TO_CHAR(ch.VERIFY_ON, 'DD-Mon-YY HH:MI AM') AS VERIFIED_ON,
                   (SELECT CASE WHEN COUNT(1) > 0 THEN 'Y' ELSE 'N' END
                      FROM SFM_OUTBOX_OTP o
                     WHERE o.CUST_ID = d.CUST_ID
                       AND o.EMP_ID = :employee_id
                       AND o.OTP_FLAG = 'Consent'
                       AND o.STATUS = 'N') AS OTP_PENDING
            FROM SFM_ROUTE_PLANS r
            INNER JOIN SFM_ROUTE_PLANS_DET d ON d.ROUTE_ID = r.ID
            INNER JOIN SFM_CUSTOMER_INFO c ON c.ID = d.CUST_ID
            LEFT JOIN SFM_CUST_HQ ch
                ON ch.CUST_ID = d.CUST_ID
               AND ch.HQ_ID = r.HQ_ID
               AND ch.DIVSN_ID = r.DIVSN_ID
            LEFT JOIN SFM_CUST_TYPES ct
                ON ct.CTYP_ID = get_custid_custtypeid(d.CUST_ID, r.HQ_ID, r.DIVSN_ID)
            WHERE r.ROUTE_SEQ = :route_id
              AND r.HQ_ID = :hq_id
              AND r.DIVSN_ID = :division_id
              AND r.STATUS = 'A'
              AND d.STATUS = 'A'
              AND d.CUST_ID = :customer_id
              AND ROWNUM = 1
            SQL, [
                'employee_id' => $employeeId,
                'route_id' => $routeId,
                'hq_id' => $scope['hq_id'],
                'division_id' => $scope['division_id'],
                'customer_id' => $customerId,
            ]);

        return $row === null ? null : $this->mapCustomer($row);
    }

    public function updateCustomer(int $employeeId, int $routeId, int $customerId, array $input): ?array
    {
        $scope = $this->employeeScope($employeeId);
        $customer = $this->customer($employeeId, $routeId, $customerId);
        if ($customer === null) {
            return null;
        }
        if ($customer['verified']) {
            throw new InvalidArgumentException('Verified customer details cannot be changed.');
        }

        $profile = $this->validateProfile($input, (int) ($customer['customer_type_id'] ?? 0));
        if ($profile['primary_phone'] === $profile['additional_phone'] && $profile['additional_phone'] !== '') {
            throw new InvalidArgumentException('Primary and additional phone numbers must be different.');
        }

        $duplicate = $this->fetchOne(<<<'SQL'
            SELECT COUNT(1) AS DUPLICATE_COUNT
            FROM SFM_CUST_HQ
            WHERE HQ_ID = :hq_id
              AND DIVSN_ID = :division_id
              AND CUST_ID <> :customer_id
              AND (
                    PRI_CELL_NUM IN (:primary_phone, :additional_phone)
                 OR SEC_CELL_NUM IN (:primary_phone, :additional_phone)
              )
            SQL, [
                'hq_id' => $scope['hq_id'],
                'division_id' => $scope['division_id'],
                'customer_id' => $customerId,
                'primary_phone' => $profile['primary_phone'],
                'additional_phone' => $profile['additional_phone'],
            ]);
        if ((int) ($duplicate['DUPLICATE_COUNT'] ?? 0) > 0) {
            throw new InvalidArgumentException('A phone number is already registered to another customer.');
        }

        $this->transaction(function () use ($profile, $scope, $customerId): void {
            $customerRows = $this->execute(<<<'SQL'
                UPDATE SFM_CUSTOMER_INFO
                SET ADDRESS = :address,
                    STATE = :state,
                    CITY = :city,
                    PINCODE = :pincode,
                    PER_EMAIL = :personal_email,
                    BUS_EMAIL = :business_email,
                    LANDMARK = :landmark,
                    LOC_URL = :location_url
                WHERE ID = :customer_id
                SQL, [
                    'address' => $profile['address'],
                    'state' => $profile['state'],
                    'city' => $profile['city'],
                    'pincode' => $profile['pincode'],
                    'personal_email' => $profile['personal_email'],
                    'business_email' => $profile['business_email'],
                    'landmark' => $profile['landmark'],
                    'location_url' => $profile['location_url'],
                    'customer_id' => $customerId,
                ]);
            $hqRows = $this->execute(<<<'SQL'
                UPDATE SFM_CUST_HQ
                SET PRI_CELL_NUM = :primary_phone,
                    SEC_CELL_NUM = :additional_phone,
                    OWNER_NAME = :owner_name
                WHERE CUST_ID = :customer_id
                  AND HQ_ID = :hq_id
                  AND DIVSN_ID = :division_id
                SQL, [
                    'primary_phone' => $profile['primary_phone'],
                    'additional_phone' => $profile['additional_phone'],
                    'owner_name' => $profile['owner_name'],
                    'customer_id' => $customerId,
                    'hq_id' => $scope['hq_id'],
                    'division_id' => $scope['division_id'],
                ]);
            if ($customerRows !== 1 || $hqRows !== 1) {
                throw new InvalidArgumentException('Customer details could not be updated for this route.');
            }
        });

        return $this->customer($employeeId, $routeId, $customerId);
    }

    public function requestConsentOtp(
        int $employeeId,
        string $loginId,
        int $routeId,
        int $customerId
    ): ?array {
        $scope = $this->employeeScope($employeeId);
        $customer = $this->customer($employeeId, $routeId, $customerId);
        if ($customer === null) {
            return null;
        }
        if ($customer['verified']) {
            throw new InvalidArgumentException('This customer is already verified.');
        }
        if (trim((string) $customer['primary_phone']) === '') {
            throw new InvalidArgumentException('Save a primary phone number before requesting an OTP.');
        }
        $this->validateProfile([
            'address' => $customer['address'],
            'state' => $customer['state'],
            'city' => $customer['city'],
            'pincode' => $customer['pincode'],
            'landmark' => $customer['landmark'],
            'location_url' => $customer['location_url'],
            'personal_email' => $customer['personal_email'],
            'business_email' => $customer['business_email'],
            'primary_phone' => $customer['primary_phone'],
            'additional_phone' => $customer['additional_phone'],
            'owner_name' => $customer['owner_name'],
        ], (int) ($customer['customer_type_id'] ?? 0));

        $pending = $this->fetchOne(<<<'SQL'
            SELECT CEIL(GREATEST(0, 120 - (SYSDATE - CHG_ON) * 86400)) AS RETRY_AFTER_SECONDS
            FROM (
                SELECT CHG_ON
                FROM SFM_OUTBOX_OTP
                WHERE CUST_ID = :customer_id
                  AND EMP_ID = :employee_id
                  AND OTP_FLAG = 'Consent'
                  AND STATUS = 'N'
                ORDER BY CHG_ON DESC, ID DESC
            )
            WHERE ROWNUM = 1
            SQL, ['customer_id' => $customerId, 'employee_id' => $employeeId]);
        $retryAfter = (int) ($pending['RETRY_AFTER_SECONDS'] ?? 0);
        if ($retryAfter > 0) {
            throw new RouteCooldownException($retryAfter);
        }

        $otp = str_pad((string) random_int(0, 9999), 4, '0', STR_PAD_LEFT);
        $this->transaction(function () use ($customer, $employeeId, $loginId, $customerId, $otp): void {
            $this->execute(<<<'SQL'
                INSERT INTO SFM_OUTBOX_OTP (
                    ID, DAYPLAN_ID, EMP_ID, DAY_DATE, CUST_ID, CUST_ID_TO,
                    OTP_NO, MOB_NO, MESSAGE, STATUS, OTP_FLAG, CHG_ON, CHG_BY
                ) VALUES (
                    NULL, NULL, :employee_id, SYSDATE, :customer_id, NULL,
                    :otp, :mobile_number, NULL, 'N', 'Consent', SYSDATE, :login_id
                )
                SQL, [
                    'employee_id' => $employeeId,
                    'customer_id' => $customerId,
                    'otp' => $otp,
                    'mobile_number' => (string) $customer['primary_phone'],
                    'login_id' => $loginId,
                ]);
        });

        return [
            'delivery_status' => 'queued',
            'retry_after_seconds' => 120,
            'message' => 'OTP queued in Oracle. SMS gateway delivery is not configured in this API yet.',
        ];
    }

    public function verifyConsentOtp(
        int $employeeId,
        string $loginId,
        int $routeId,
        int $customerId,
        string $otp
    ): ?bool {
        $scope = $this->employeeScope($employeeId);
        $customer = $this->customer($employeeId, $routeId, $customerId);
        if ($customer === null) {
            return null;
        }
        if ($customer['verified']) {
            return true;
        }

        $pending = $this->fetchOne(<<<'SQL'
            SELECT ID
            FROM SFM_OUTBOX_OTP
            WHERE CUST_ID = :customer_id
              AND EMP_ID = :employee_id
              AND OTP_NO = :otp
              AND OTP_FLAG = 'Consent'
              AND STATUS = 'N'
            ORDER BY CHG_ON DESC, ID DESC
            FETCH FIRST 1 ROWS ONLY
            SQL, [
                'customer_id' => $customerId,
                'employee_id' => $employeeId,
                'otp' => $otp,
            ]);
        if ($pending === null) {
            return false;
        }

        $this->transaction(function () use ($scope, $customerId, $loginId, $pending): void {
            $updated = $this->execute(<<<'SQL'
                UPDATE SFM_CUST_HQ
                SET CONSENT_FLAG = 'Y',
                    VERIFY_ON = SYSDATE,
                    VERIFY_BY = :login_id
                WHERE CUST_ID = :customer_id
                  AND DIVSN_ID = :division_id
                  AND HQ_ID = :hq_id
                  AND NVL(CONSENT_FLAG, 'N') <> 'Y'
                SQL, [
                    'login_id' => $loginId,
                    'customer_id' => $customerId,
                    'division_id' => $scope['division_id'],
                    'hq_id' => $scope['hq_id'],
                ]);
            if ($updated !== 1) {
                throw new InvalidArgumentException('Customer verification could not be updated.');
            }
            $this->execute(
                "UPDATE SFM_OUTBOX_OTP SET STATUS = 'A' WHERE ID = :otp_id AND STATUS = 'N'",
                ['otp_id' => (int) $pending['ID']]
            );
        });
        return true;
    }

    private function employeeScope(int $employeeId): array
    {
        $division = $this->fetchOne(<<<'SQL'
            SELECT DIVSN_ID
            FROM (
                SELECT d.DIVSN_ID
                FROM SFM_EMP_DIVISIONS d
                INNER JOIN SFM_DIVISIONS v
                    ON v.DIVSN_ID = d.DIVSN_ID
                WHERE d.EMP_ID = :employee_id
                AND TRUNC(SYSDATE) BETWEEN TRUNC(d.EFFEC_FROM)
                    AND NVL(TRUNC(d.EFFEC_TO), DATE '3000-01-01')
                ORDER BY v.SEQ, d.DIVSN_ID
            )
            WHERE ROWNUM = 1
            SQL, ['employee_id' => $employeeId]);

        $hq = $this->fetchOne(
            'SELECT get_empid_hqid(:employee_id, TRUNC(SYSDATE)) AS HQ_ID FROM DUAL',
            ['employee_id' => $employeeId]
        );
        if ($division === null || $hq === null || $hq['HQ_ID'] === null) {
            throw new InvalidArgumentException('No active route geography is configured for this employee.');
        }

        return [
            'hq_id' => (int) $hq['HQ_ID'],
            'division_id' => (int) $division['DIVSN_ID'],
        ];
    }

    private function findRoute(int $routeId, array $scope): ?array
    {
        $row = $this->fetchOne(<<<'SQL'
            SELECT ROUTE_SEQ AS ROUTE_ID,
                   INITCAP(GET_TOWN_DESC(TOWN_ID)) AS TOWN
            FROM SFM_ROUTE_PLANS
            WHERE ROUTE_SEQ = :route_id
              AND HQ_ID = :hq_id
              AND DIVSN_ID = :division_id
              AND STATUS = 'A'
              AND ROWNUM = 1
            SQL, [
                'route_id' => $routeId,
                'hq_id' => $scope['hq_id'],
                'division_id' => $scope['division_id'],
            ]);
        return $row === null ? null : [
            'route_id' => (int) $row['ROUTE_ID'],
            'town' => (string) $row['TOWN'],
        ];
    }

    private function mapCustomer(array $row): array
    {
        return [
            'customer_id' => (int) $row['CUSTOMER_ID'],
            'name' => (string) $row['CUSTOMER_NAME'],
            'customer_type' => (string) ($row['CUSTOMER_TYPE'] ?? ''),
            'customer_type_id' => $row['CUSTOMER_TYPE_ID'] === null
                ? null
                : (int) $row['CUSTOMER_TYPE_ID'],
            'address' => (string) ($row['ADDRESS'] ?? ''),
            'state' => (string) ($row['STATE'] ?? ''),
            'city' => (string) ($row['CITY'] ?? ''),
            'pincode' => (string) ($row['PINCODE'] ?? ''),
            'landmark' => (string) ($row['LANDMARK'] ?? ''),
            'location_url' => (string) ($row['LOC_URL'] ?? ''),
            'personal_email' => (string) ($row['PERSONAL_EMAIL'] ?? ''),
            'business_email' => (string) ($row['BUSINESS_EMAIL'] ?? ''),
            'primary_phone' => (string) ($row['PRIMARY_PHONE'] ?? ''),
            'additional_phone' => (string) ($row['ADDITIONAL_PHONE'] ?? ''),
            'owner_name' => (string) ($row['OWNER_NAME'] ?? ''),
            'verified' => $row['CONSENT_FLAG'] === 'Y',
            'verified_on' => $row['VERIFIED_ON'],
            'otp_pending' => $row['OTP_PENDING'] === 'Y',
        ];
    }

    private function validateProfile(array $input, int $customerTypeId): array
    {
        $profile = [];
        foreach ([
            'address' => 50,
            'state' => 100,
            'city' => 50,
            'pincode' => 20,
            'landmark' => 50,
            'location_url' => 500,
            'personal_email' => 50,
            'business_email' => 50,
            'primary_phone' => 10,
            'additional_phone' => 10,
            'owner_name' => 50,
        ] as $field => $limit) {
            $value = trim((string) ($input[$field] ?? ''));
            if (strlen($value) > $limit) {
                throw new InvalidArgumentException("$field must be no longer than $limit characters.");
            }
            $profile[$field] = $value;
        }

        foreach (['address', 'state', 'city', 'pincode', 'personal_email', 'primary_phone'] as $required) {
            if ($profile[$required] === '') {
                throw new InvalidArgumentException("$required is required.");
            }
        }
        if (!filter_var($profile['personal_email'], FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('personal_email must be a valid email address.');
        }
        if ($profile['business_email'] !== '' &&
            !filter_var($profile['business_email'], FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('business_email must be a valid email address.');
        }
        if (!preg_match('/^\d{10}$/', $profile['primary_phone'])) {
            throw new InvalidArgumentException('primary_phone must contain exactly 10 digits.');
        }
        if ($profile['additional_phone'] !== '' &&
            !preg_match('/^\d{10}$/', $profile['additional_phone'])) {
            throw new InvalidArgumentException('additional_phone must contain exactly 10 digits.');
        }
        if (!in_array($customerTypeId, self::REQUIRED_OWNER_EXEMPT_TYPES, true) &&
            $profile['owner_name'] === '') {
            throw new InvalidArgumentException('owner_name is required for this customer type.');
        }

        return [
            'address' => $profile['address'],
            'state' => $profile['state'],
            'city' => $profile['city'],
            'pincode' => $profile['pincode'],
            'landmark' => $profile['landmark'],
            'location_url' => $profile['location_url'],
            'personal_email' => $profile['personal_email'],
            'business_email' => $profile['business_email'],
            'primary_phone' => $profile['primary_phone'],
            'additional_phone' => $profile['additional_phone'],
            'owner_name' => $profile['owner_name'],
        ];
    }
}
