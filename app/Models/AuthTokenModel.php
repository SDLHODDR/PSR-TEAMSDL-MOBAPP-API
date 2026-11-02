<?php

declare(strict_types=1);

namespace App\Models;

use DateTimeImmutable;
use SDL\Framework\Database;
use SDL\Framework\Model;

/** Oracle persistence for opaque tokens. Raw access/refresh tokens are never stored. */
final class AuthTokenModel extends Model
{
    public function __construct(Database $db)
    {
        parent::__construct($db);
    }

    /** @param array{hash:string,type:string,family_id:string,expires_at:DateTimeImmutable,emp_id:int,login_id:string,created_ip:string} $accessToken
     *  @param array{hash:string,type:string,family_id:string,expires_at:DateTimeImmutable,emp_id:int,login_id:string,created_ip:string} $refreshToken
     */
    public function storeTokenPair(array $accessToken, array $refreshToken): void
    {
        $this->transaction(function () use ($accessToken, $refreshToken): void {
            $this->insertToken($accessToken);
            $this->insertToken($refreshToken);
        });
    }

    /** Return active access-token metadata only; never return the raw token. */
    public function findValidAccessToken(string $tokenHash): ?array
    {
        return $this->fetchOne(<<<'SQL'
            SELECT TOKEN_ID, EMP_ID, LOGIN_ID, FAMILY_ID, EXPIRES_AT
            FROM SFM_APPUSER_AUTH_TOKENS
            WHERE TOKEN_HASH = RPAD(:token_hash, 64, ' ')
              AND TOKEN_TYPE = 'A'
              AND REVOKED_AT IS NULL
              AND EXPIRES_AT > SYSTIMESTAMP
            SQL, ['token_hash' => $tokenHash]);
    }

    /**
     * Rotate a refresh token atomically. SELECT FOR UPDATE serializes concurrent
     * refresh attempts. Reuse of a token with REPLACED_BY_ID set revokes its family.
     *
     * Return status: rotated | invalid | replay | inactive_employee.
     * Caller receives no raw token unless the rotation committed successfully.
     *
     * @param array{hash:string,type:string,family_id:string,expires_at:DateTimeImmutable,emp_id:int,login_id:string,created_ip:string} $accessToken
     * @param array{hash:string,type:string,family_id:string,expires_at:DateTimeImmutable,emp_id:int,login_id:string,created_ip:string} $refreshToken
     */
    public function rotateRefreshToken(
        string $refreshHash,
        array $accessToken,
        array $refreshToken,
        callable $isEmployeeActive
    ): array {
        return $this->transaction(function () use ($refreshHash, $accessToken, $refreshToken, $isEmployeeActive): array {
            $old = $this->fetchOne(<<<'SQL'
                SELECT TOKEN_ID, EMP_ID, LOGIN_ID, FAMILY_ID, TOKEN_TYPE,
                       REVOKED_AT, REPLACED_BY_ID,
                       CASE WHEN EXPIRES_AT > SYSTIMESTAMP THEN 1 ELSE 0 END AS IS_UNEXPIRED
                FROM SFM_APPUSER_AUTH_TOKENS
                WHERE TOKEN_HASH = RPAD(:token_hash, 64, ' ')
                FOR UPDATE
                SQL, ['token_hash' => $refreshHash]);

            if ($old === null || ($old['TOKEN_TYPE'] ?? null) !== 'R') {
                return ['status' => 'invalid'];
            }

            $familyId = rtrim((string) ($old['FAMILY_ID'] ?? ''));
            if ($familyId === '') {
                return ['status' => 'invalid'];
            }

            // A replaced refresh token is evidence of reuse. Revoke every token
            // in the family, including the replacement session, in this transaction.
            if (($old['REPLACED_BY_ID'] ?? null) !== null) {
                $this->revokeFamilyWithinTransaction($familyId);
                return ['status' => 'replay'];
            }

            if (($old['REVOKED_AT'] ?? null) !== null || (int) ($old['IS_UNEXPIRED'] ?? 0) !== 1) {
                return ['status' => 'invalid'];
            }

            $employeeId = (int) ($old['EMP_ID'] ?? 0);
            if ($employeeId <= 0 || !$isEmployeeActive($employeeId)) {
                $this->revokeFamilyWithinTransaction($familyId);
                return ['status' => 'inactive_employee'];
            }

            // Carry the locked row's identity into the new token pair.
            foreach (['emp_id' => (int) $old['EMP_ID'], 'login_id' => (string) $old['LOGIN_ID'], 'family_id' => $familyId] as $key => $value) {
                $accessToken[$key] = $value;
                $refreshToken[$key] = $value;
            }

            // Revoke the previous access token(s) before inserting the new access token.
            $this->execute(<<<'SQL'
                UPDATE SFM_APPUSER_AUTH_TOKENS
                SET REVOKED_AT = SYSTIMESTAMP
                WHERE FAMILY_ID = RPAD(:family_id, 32, ' ')
                  AND TOKEN_TYPE = 'A'
                  AND REVOKED_AT IS NULL
                SQL, ['family_id' => $familyId]);

            $this->execute(<<<'SQL'
                UPDATE SFM_APPUSER_AUTH_TOKENS
                SET REVOKED_AT = SYSTIMESTAMP,
                    LAST_USED_AT = SYSTIMESTAMP
                WHERE TOKEN_ID = :token_id
                  AND REVOKED_AT IS NULL
                SQL, ['token_id' => (int) $old['TOKEN_ID']]);

            $this->insertToken($accessToken);
            $this->insertToken($refreshToken);

            $newRefresh = $this->fetchOne(<<<'SQL'
                SELECT TOKEN_ID
                FROM SFM_APPUSER_AUTH_TOKENS
                WHERE TOKEN_HASH = RPAD(:token_hash, 64, ' ')
                SQL, ['token_hash' => $refreshToken['hash']]);

            if ($newRefresh === null || !isset($newRefresh['TOKEN_ID'])) {
                throw new \RuntimeException('Unable to resolve the replacement refresh token row.');
            }

            $this->execute(<<<'SQL'
                UPDATE SFM_APPUSER_AUTH_TOKENS
                SET REPLACED_BY_ID = :replacement_id
                WHERE TOKEN_ID = :old_token_id
                SQL, [
                    'replacement_id' => (int) $newRefresh['TOKEN_ID'],
                    'old_token_id' => (int) $old['TOKEN_ID'],
                ]);

            return ['status' => 'rotated', 'emp_id' => $employeeId, 'login_id' => (string) $old['LOGIN_ID'], 'family_id' => $familyId];
        });
    }

    /** Revoke all access and refresh tokens belonging to one session family. */
    public function revokeFamily(string $familyId): void
    {
        $this->transaction(function () use ($familyId): void {
            $this->revokeFamilyWithinTransaction($familyId);
        });
    }

    private function revokeFamilyWithinTransaction(string $familyId): void
    {
        $this->execute(<<<'SQL'
            UPDATE SFM_APPUSER_AUTH_TOKENS
            SET REVOKED_AT = COALESCE(REVOKED_AT, SYSTIMESTAMP)
            WHERE FAMILY_ID = RPAD(:family_id, 32, ' ')
            SQL, ['family_id' => $familyId]);
    }

    private function insertToken(array $token): void
    {
        $this->execute(<<<'SQL'
            INSERT INTO SFM_APPUSER_AUTH_TOKENS (
                EMP_ID, LOGIN_ID, TOKEN_HASH, TOKEN_TYPE, FAMILY_ID,
                CREATED_AT, EXPIRES_AT, CREATED_IP
            ) VALUES (
                :emp_id, :login_id, RPAD(:token_hash, 64, ' '), :token_type,
                RPAD(:family_id, 32, ' '), SYSTIMESTAMP,
                TO_TIMESTAMP_TZ(
                    :expires_at,
                    'YYYY-MM-DD"T"HH24:MI:SS.FF6TZH:TZM'
                ), :created_ip
            )
            SQL, [
                'emp_id' => $token['emp_id'],
                'login_id' => $token['login_id'],
                'token_hash' => $token['hash'],
                'token_type' => $token['type'],
                'family_id' => $token['family_id'],
                'expires_at' => $token['expires_at']->format('Y-m-d\\TH:i:s.uP'),
                'created_ip' => $token['created_ip'],
            ]);
    }
}
