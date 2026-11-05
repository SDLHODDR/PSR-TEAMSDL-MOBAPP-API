<?php
declare(strict_types=1);
namespace App\Models;

// ini_set('display_errors', 1);
// ini_set('display_startup_errors', 1);
// error_reporting(E_ALL);


use SDL\Framework\Database;
use SDL\Framework\Model;
use SDL\Framework\Helpers;

final class EmployeeAuthModel extends Model
{
    public function __construct(Database $db) { parent::__construct($db); }

    public function findActiveEmployeeByCredentials(
        string $username, 
        string $encodedPassword
    ): ?array {
        $userIp = Helpers::getUserIpAddress();
        $appAccess = $this->fetchOne(
            'SELECT ID FROM SFM_BCS_APP_ACCESS WHERE EMP_CODE = :username AND APP_ID = 2',
            ['username' => $username]
        );
        if ($appAccess === null || empty($appAccess['ID'])) {
            return null;
        }
        Helpers::printArr($appAccess);

        $employee = $this->fetchOne(<<<'SQL'
            SELECT a.EMP_CODE AS LOGIN_ID, b.ID AS EMP_ID
            FROM SFM_BCS_USERS a
            INNER JOIN SFM_EMPLOYEE_INFO b ON a.EMP_CODE = b.EMP_CODE
            WHERE a.CODE = :username
              AND a.PASS_WD = :encoded_password
              AND a.STATUS = 'A'
              AND (a.BLOCK_TILL IS NULL OR a.BLOCK_TILL <= SYSTIMESTAMP)
              AND b.STATUS = 'A'
            SQL, ['username' => $username, 'encoded_password' => $encodedPassword]);

        if ($employee !== null && !empty($employee['EMP_ID'])) {
            $this->transaction(fn (): string => $this->insertLoginAttempt($username, $userIp, 'Y'));
            return $employee;
        }

        $this->recordFailedLogin($username, $userIp);
        return null;
    }

    private function recordFailedLogin(string $username, string $userIp): void
    {
        $this->transaction(fn (): string => $this->insertLoginAttempt($username, $userIp, 'N'));
        $incorrectCount = $this->fetchOne(<<<'SQL'
            SELECT COUNT(1) AS CNT
            FROM (
                SELECT EMP_CODE, ID, ISSUCCESS
                FROM SFM_BCS_USER_LOGIN_LOG
                WHERE EMP_CODE = :username
                  AND TRUNC(SYSDATE) = TRUNC(ATTEMPT_ON)
                ORDER BY ID DESC
                FETCH FIRST 4 ROWS ONLY
            )
            WHERE ISSUCCESS = 'N'
            SQL, ['username' => $username]);

        if ((int) ($incorrectCount['CNT'] ?? 0) < 3) {
            return;
        }

        $this->transaction(function () use ($username): void {
            $this->execute(<<<'SQL'
                UPDATE SFM_BCS_USERS
                SET BLOCK_TILL = SYSTIMESTAMP + INTERVAL '10' MINUTE
                WHERE EMP_CODE = :username
                  AND (BLOCK_TILL IS NULL OR BLOCK_TILL <= SYSTIMESTAMP)
                SQL, ['username' => $username]);
        });
    }

    /** Load the complete employee context only after bearer-token authentication. */
    public function findEmployeeContextById(int $employeeId): ?array
    {
        $employee = $this->findActiveEmployeeById($employeeId);
        if ($employee === null) {
            return null;
        }

        $details = $this->fetchOne(<<<'SQL'
            SELECT EMP_CODE, (FNAME || ' ' || LNAME) AS EMP_NAME,
                   LEVEL_ID, PICS, MANAGER_ID, COM_EMAIL
            FROM SFM_EMPLOYEE_INFO
            WHERE ID = :employee_id AND STATUS = 'A'
            SQL, ['employee_id' => $employeeId]);
        if ($details === null) {
            return null;
        }

        $divisions = $this->fetchAll(<<<'SQL'
            SELECT DIVSN_ID
            FROM SFM_EMP_DIVISIONS
            WHERE EMP_ID = :employee_id
              AND TRUNC(SYSDATE) BETWEEN EFFEC_FROM AND NVL(EFFEC_TO, DATE '3000-01-01')
            ORDER BY DIVSN_ID
            SQL, ['employee_id' => $employeeId]);

        $hq = $this->fetchOne(<<<'SQL'
            SELECT g.GEO_ID, g.GEO_DESC, g.GEO_LABEL, g.DIVSN_ID,
                   (e.FNAME || ' ' || e.MNAME || ' ' || e.LNAME) AS EMP_NAME, e.LEVEL_ID,
                   g.GEO_LVL, g.EMP_ID, e.EMP_CODE, e.PICS, e.MANAGER_ID,
                   e.COM_EMAIL
            FROM (
                SELECT GEO_ID, GEO_DESC, GEO_LABEL, DIVSN_ID, EMP_ID, GEO_HCLS_ID, GEO_LVL
                FROM TABLE(GEOEMP.LIST_DOWN(:employee_id, NULL, TRUNC(ADD_MONTHS(SYSDATE, -24))))
                WHERE EMP_ID = :employee_id
            ) g
            INNER JOIN SFM_EMPLOYEE_INFO e ON g.EMP_ID = e.ID
            SQL, ['employee_id' => $employeeId]);
            //REmove ADD_MONTHS(SYSDATE,24) and replace SYSDATE
        $division = null;
        if ($hq !== null && !empty($hq['DIVSN_ID'])) {
            $division = $this->fetchOne(
                'SELECT DIVSN_DESC FROM SFM_DIVISIONS WHERE DIVSN_ID = :division_id',
                ['division_id' => $hq['DIVSN_ID']]
            );
        }
        // $financialYear = $this->fetchOne(<<<'SQL'
        //     SELECT LABEL, TO_CHAR(FROM_DT, 'dd-Mon-yyyy') AS FROMDATE,
        //            TO_CHAR(TO_DATE, 'dd-Mon-yyyy') AS TODATE
        //     FROM SFM_FINYEAR
        //     WHERE TRUNC(SYSDATE) BETWEEN FROM_DT AND TO_DATE
        //     SQL, []);

        // $financialYear = $this->fetchOne(<<<'SQL'
        //     SELECT
        //         LABEL,
        //         TO_CHAR(FROM_DT, 'DD-Mon-YYYY') FROMDATE,
        //         TO_CHAR(TO_DATE, 'DD-Mon-YYYY') TODATE
        //     FROM SFM_FINYEAR
        //     WHERE LABEL = 'F25'
        // SQL, []);

        $financialYear = $this->fetchOne(<<<'SQL'
            WITH FINYEAR AS (
            SELECT
                LABEL,
                FROM_DT,
                TO_DATE,
                ROW_NUMBER() OVER (
                    ORDER BY
                        CASE
                            WHEN TRUNC(SYSDATE)
                                BETWEEN FROM_DT AND TO_DATE
                            THEN 1
                            ELSE 2
                        END,
                        FROM_DT DESC
                ) RN
            FROM SFM_FINYEAR
        )
        SELECT
            LABEL,
            TO_CHAR(FROM_DT, 'DD-Mon-YYYY') FROMDATE,
            TO_CHAR(TO_DATE, 'DD-Mon-YYYY') TODATE
        FROM FINYEAR
        WHERE RN = 1
        SQL, []);

        return [
            'login_id' => $employee['LOGIN_ID'], 
            'login_type' => 'E',
            'emp_id' => $employee['EMP_ID'], 
            'emp_code' => $hq['EMP_CODE'] ?? $details['EMP_CODE'],
            'emp_name' => $hq['EMP_NAME'] ?? $details['EMP_NAME'],
            'emp_email' => $hq['COM_EMAIL'] ?? $details['COM_EMAIL'],
            'emp_pic' => $hq['PICS'] ?? $details['PICS'],
            'emp_level' => $hq['LEVEL_ID'] ?? $details['LEVEL_ID'],
            'manager_id' => $hq['MANAGER_ID'] ?? $details['MANAGER_ID'],
            'divisions' => Helpers::column($divisions, 'DIVSN_ID'),
            'division_name' => $division['DIVSN_DESC'] ?? null,
            'geography' => [
                'geo_id' => $hq['GEO_ID'] ?? $employee['GEO_ID'],
                'new_geo_id' => $hq['GEO_ID'] ?? $employee['GEO_ID'],
                'geo_level' => $hq['GEO_LVL'] ?? $employee['GEO_LEVEL'],
                'geo_name' => $hq['GEO_DESC'] ?? null,
                'hq_label' => $hq['GEO_LABEL'] ?? null,
            ],
            'financial_year' => $financialYear === null ? null : [
                'label' => $financialYear['LABEL'], 
                'from_date' => $financialYear['FROMDATE'],
                'to_date' => $financialYear['TODATE'],
            ],
        ];
    }

    /** @return list<int> */
    public function getEmpDivisions(int $employeeId): array
    {
        $employeeDiv = $this->fetchOne(<<<'SQL'
            SELECT STATUS, TO_CHAR(DOL, 'dd-Mon-yyyy') DOL FROM SFM_EMPLOYEE_INFO WHERE ID = :employee_id
            SQL, ['employee_id' => $employeeId]);

        if ($employeeDiv === null) {
            return [];
        }

        $dateDiv = ($employeeDiv['STATUS'] ?? null) === 'R'
            ? ($employeeDiv['DOL'] ?? null)
            : null;

        $divisions = $this->fetchAll(<<<'SQL'
            SELECT d.DIVSN_ID
            FROM SFM_EMP_DIVISIONS d
            INNER JOIN SFM_DIVISIONS v ON v.DIVSN_ID = d.DIVSN_ID
            WHERE d.EMP_ID = :employee_id
              AND NVL(TO_DATE(:effective_date, 'DD-Mon-YYYY'), TRUNC(SYSDATE)) BETWEEN
              TRUNC(d.EFFEC_FROM) AND NVL(TRUNC(d.EFFEC_TO), DATE '3000-01-01')
            ORDER BY v.SEQ, d.DIVSN_ID
            SQL, [
                'employee_id' => $employeeId,
                'effective_date' => $dateDiv,
            ]);

        return array_map(
            static fn (array $division): int => (int) $division['DIVSN_ID'],
            $divisions
        );

    }

    private function sfmLoginLog(
        string $username,
        string $userIp,
        string $empIDLG
    ): string {
        $sql = 
            <<<'SQL'
                INSERT INTO SFM_LOGIN_LOG(
                    LOGIN_ID, EMP_ID, CLIENT_IP, LOGIN_DT
                ) VALUES (
                    :username, :empId, :ip_addr, SYSDATE
                )
                RETURNING ID INTO :return_id
            SQL;

        return $this->executeReturning(
            $sql, [
                'username'   => $username,
                'empId' => $empIDLG,
                'ip_addr'    => $userIp
            ],
            ':return_id'
        );
    }

    private function insertLoginAttempt(
        string $username,
        string $userIp,
        string $isSuccess = 'N'
    ): string {
        $sql = 
            <<<'SQL'
                INSERT INTO SFM_BCS_USER_LOGIN_LOG (
                    EMP_CODE, ISSUCCESS, IP_ADDR, ATTEMPT_ON, APP_ID
                ) VALUES (
                    :emp_code, :is_success, :ip_addr, SYSDATE, :app_id
                )
                RETURNING ID INTO :return_id
            SQL;

        return $this->executeReturning(
            $sql, [
                'emp_code'   => $username,
                'is_success' => $isSuccess,
                'ip_addr'    => $userIp,
                'app_id'     => 2
            ],
            ':return_id'
        );
    }

    public function findActiveEmployeeById(int $employeeId): ?array
    {
        // $sql = <<<'SQL'
        //     SELECT a.EMP_CODE AS LOGIN_ID, b.ID AS EMP_ID, b.GEO_ID, b.GEO_LEVEL
        //     FROM BUSERS a
        //     INNER JOIN SFM_EMP_INFO b ON a.EMP_CODE = b.EMP_CODE
        //     WHERE b.ID = :emp_id
        //       AND a.STATUS = 'A'
        //       AND b.STATUS = 'A'
        //     SQL;
        $sql = <<<'SQL'
            SELECT a.EMP_CODE AS LOGIN_ID, b.ID AS EMP_ID, b.GEO_ID, b.GEO_LEVEL
            FROM SFM_BCS_USERS a
            INNER JOIN SFM_EMPLOYEE_INFO b ON a.EMP_CODE = b.EMP_CODE
            WHERE b.ID = :emp_id
              AND a.STATUS = 'A'
              AND b.STATUS = 'A'
            SQL;

        return $this->fetchOne($sql, ['emp_id' => $employeeId]);
    }
}
