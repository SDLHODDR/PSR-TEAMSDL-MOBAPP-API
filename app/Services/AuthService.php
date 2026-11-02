<?php

declare(strict_types=1);

namespace App\Services;

// ini_set('display_errors', 1);
// ini_set('display_startup_errors', 1);
// error_reporting(E_ALL);

use App\Models\AuthTokenModel;
use App\Models\EmployeeAuthModel;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

final class AuthService
{
    public function __construct(
        private readonly EmployeeAuthModel $employeeAuthModel,
        private readonly LegacyPasswordEncoder $passwordEncoder,
        private readonly AuthTokenModel $authTokenModel,
        private readonly TokenService $tokenService
    ) {
    }

    public function verifyEmployeeCredentials(string $username, string $password): ?array
    {
        $employee = $this->employeeAuthModel->findActiveEmployeeByCredentials(
            $username,
            $this->passwordEncoder->encode($password)
        );
        return $employee === null ? null : $this->normalizeEmployee($employee);
    }

    public function loginAndIssueTokens(string $username, string $password, string $clientIp): ?array
    {
        $employee = $this->verifyEmployeeCredentials($username, $password);
        if ($employee === null) {
            return null;
        }
        if (!is_numeric($employee['emp_id']) || (int) $employee['emp_id'] <= 0) {
            throw new InvalidArgumentException('The employee record has an invalid identifier.');
        }

        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $familyId = $this->tokenService->generateFamilyId();
        $accessToken = $this->tokenService->generateAccessToken();
        $refreshToken = $this->tokenService->generateRefreshToken();
        $accessExpiry = $this->tokenService->accessTokenExpiry($now);
        $refreshExpiry = $this->tokenService->refreshTokenExpiry($now);
        $common = [
            'emp_id' => (int) $employee['emp_id'],
            'login_id' => (string) $employee['login_id'],
            'family_id' => $familyId,
            'created_ip' => substr($clientIp, 0, 45),
        ];

        $this->authTokenModel->storeTokenPair(
            $common + ['hash' => $this->tokenService->hashToken($accessToken), 'type' => 'A', 'expires_at' => $accessExpiry],
            $common + ['hash' => $this->tokenService->hashToken($refreshToken), 'type' => 'R', 'expires_at' => $refreshExpiry]
        );

        return $this->tokenResponse($accessToken, $refreshToken, $accessExpiry, $refreshExpiry);
    }

    /** Validate and atomically rotate an opaque refresh token. */
    public function refreshTokens(string $rawRefreshToken, string $clientIp): array
    {
        // 48 random bytes become exactly 64 base64url characters without padding.
        if (strlen($rawRefreshToken) !== 64 || !preg_match('/^[A-Za-z0-9_-]{64}$/', $rawRefreshToken)) {
            return ['status' => 'invalid'];
        }

        $refreshHash = $this->tokenService->hashToken($rawRefreshToken);
        // Generate candidates before entering the DB transaction; only hashes are persisted.
        $accessToken = $this->tokenService->generateAccessToken();
        $newRefreshToken = $this->tokenService->generateRefreshToken();
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $accessExpiry = $this->tokenService->accessTokenExpiry($now);
        $refreshExpiry = $this->tokenService->refreshTokenExpiry($now);

        // The family and employee identifiers are taken from the locked persisted row
        // inside rotateRefreshToken; candidates are completed there via a short callback.
        $result = $this->authTokenModel->rotateRefreshToken(
            $refreshHash,
            [
                'hash' => $this->tokenService->hashToken($accessToken),
                'type' => 'A',
                'family_id' => '', // replaced from locked row by model below
                'expires_at' => $accessExpiry,
                'emp_id' => 0,
                'login_id' => '',
                'created_ip' => substr($clientIp, 0, 45),
            ],
            [
                'hash' => $this->tokenService->hashToken($newRefreshToken),
                'type' => 'R',
                'family_id' => '', // replaced from locked row by model below
                'expires_at' => $refreshExpiry,
                'emp_id' => 0,
                'login_id' => '',
                'created_ip' => substr($clientIp, 0, 45),
            ],
            fn (int $employeeId): bool => $this->employeeAuthModel->findActiveEmployeeById($employeeId) !== null
        );

        if (($result['status'] ?? null) !== 'rotated') {
            return ['status' => (string) ($result['status'] ?? 'invalid')];
        }

        // The model updates token metadata from the locked refresh row before insert.
        $employee = $this->employeeAuthModel->findActiveEmployeeById((int) ($result['emp_id'] ?? 0));
        if ($employee === null) {
            // Middleware also rechecks active employee status on every access.
            return ['status' => 'invalid'];
        }
        $normalized = $this->normalizeEmployee($employee);
        return [
            'status' => 'rotated',
            'tokens' => $this->tokenResponse($accessToken, $newRefreshToken, $accessExpiry, $refreshExpiry),
        ];
    }

    private function tokenResponse(string $accessToken, string $refreshToken, DateTimeImmutable $accessExpiry, DateTimeImmutable $refreshExpiry): array
    {
        return [
            'access_token' => $accessToken,
            'refresh_token' => $refreshToken,
            'expires_in' => TokenService::ACCESS_TOKEN_TTL_SECONDS,
            'refresh_expires_in' => TokenService::REFRESH_TOKEN_TTL_SECONDS,
            'access_expires_at' => $accessExpiry->format(DATE_ATOM),
            'refresh_expires_at' => $refreshExpiry->format(DATE_ATOM),
        ];
    }

    private function normalizeEmployee(array $employee): array
    {
        return [
            'login_id' => $employee['LOGIN_ID'] ?? $employee['login_id'] ?? null,
            'emp_id' => $employee['EMP_ID'] ?? $employee['emp_id'] ?? null,
            'geo_id' => $employee['GEO_ID'] ?? $employee['geo_id'] ?? null,
            'geo_level' => $employee['GEO_LEVEL'] ?? $employee['geo_level'] ?? null,
        ];
    }
}
