<?php

declare(strict_types=1);

namespace App\Controllers;

// ini_set('display_errors', 1);
// ini_set('display_startup_errors', 1);
// error_reporting(E_ALL);

use App\Models\AuthTokenModel;
use App\Models\EmployeeAuthModel;
use App\Services\AuthService;
use App\Services\LegacyPasswordEncoder;
use App\Services\TokenService;
use SDL\Framework\Controller;
use SDL\Framework\Database;
use SDL\Framework\Request;
use SDL\Framework\Response;
use Throwable;

final class AuthController extends Controller
{
    public function login(Request $request): Response
    {
        $body = $request->body();
        $username = $body['username'] ?? null;
        $password = $body['password'] ?? null;
        $errors = [];
        if (!is_string($username) || trim($username) === '') $errors['username'] = ['Username is required.'];
        if (!is_string($password) || $password === '') $errors['password'] = ['Password is required.'];
        if ($errors !== []) return $this->failure('Validation failed.', 422, 'validation_failed', $errors);
        $username = trim($username);
        if (strlen($username) > 100 || strlen($password) > 1024) return $this->failure('Invalid login credentials.', 401, 'invalid_credentials');

        try {
            $db = Database::fromEnvironment();
            $service = new AuthService(new EmployeeAuthModel($db), new LegacyPasswordEncoder(), new AuthTokenModel($db), new TokenService());
            $result = $service->loginAndIssueTokens($username, $password, $request->ip());
        } catch (Throwable $exception) {
            error_log('[SDL PSR API] Login/token issuance failed: ' . get_class($exception));
            return $this->failure('Login service is temporarily unavailable.', 503, 'authentication_service_unavailable');
        }
        if ($result === null) return $this->failure('Invalid login credentials.', 401, 'invalid_credentials');
        return $this->success([
            'token_type' => 'Bearer',
            'access_token' => $result['access_token'], 'expires_in' => $result['expires_in'],
            'access_expires_at' => $result['access_expires_at'], 'refresh_token' => $result['refresh_token'],
            'refresh_expires_in' => $result['refresh_expires_in'], 'refresh_expires_at' => $result['refresh_expires_at'],
        ], 'Login successful.');
    }

    /** Public endpoint; possession of a valid refresh token is required. */
    public function refresh(Request $request): Response
    {
        $rawRefreshToken = $request->body()['refresh_token'] ?? null;
        if (!is_string($rawRefreshToken) || $rawRefreshToken === '') {
            return $this->failure('Refresh token is required.', 422, 'validation_failed', ['refresh_token' => ['Refresh token is required.']]);
        }
        try {
            $db = Database::fromEnvironment();
            $service = new AuthService(new EmployeeAuthModel($db), new LegacyPasswordEncoder(), new AuthTokenModel($db), new TokenService());
            $result = $service->refreshTokens($rawRefreshToken, $request->ip());
        } catch (Throwable $exception) {
            error_log('[SDL PSR API] Refresh-token operation failed: ' . get_class($exception));
            return $this->failure('Authentication service is temporarily unavailable.', 503, 'authentication_service_unavailable');
        }
        if (($result['status'] ?? '') !== 'rotated') {
            // Do not reveal whether a token was unknown, expired, revoked, or replayed.
            return $this->failure('The refresh token is invalid or expired. Please sign in again.', 401, 'invalid_refresh_token');
        }
        $tokens = $result['tokens'];
        return $this->success([
            'token_type' => 'Bearer',
            'access_token' => $tokens['access_token'], 'expires_in' => $tokens['expires_in'],
            'access_expires_at' => $tokens['access_expires_at'], 'refresh_token' => $tokens['refresh_token'],
            'refresh_expires_in' => $tokens['refresh_expires_in'], 'refresh_expires_at' => $tokens['refresh_expires_at'],
        ], 'Tokens refreshed successfully.');
    }

    /** Protected endpoint: revoke all tokens belonging to this authenticated session family. */
    public function logout(Request $request): Response
    {
        $familyId = $request->attribute('authenticated_family_id');
        if (!is_string($familyId) || $familyId === '') return $this->failure('Authentication is required.', 401, 'unauthorized');
        try {
            $db = Database::fromEnvironment();
            (new AuthTokenModel($db))->revokeFamily($familyId);
        } catch (Throwable $exception) {
            error_log('[SDL PSR API] Logout failed: ' . get_class($exception));
            return $this->failure('Authentication service is temporarily unavailable.', 503, 'authentication_service_unavailable');
        }
        return $this->success(['logged_out' => true], 'Logout successful.');
    }

    public function me(Request $request): Response
    {
        $employeeId = $request->attribute('authenticated_employee_id');
        if (!is_int($employeeId) || $employeeId <= 0) {
            return $this->failure('Authentication is required.', 401, 'unauthorized');
        }
        try {
            $db = Database::fromEnvironment();
            $employee = (new EmployeeAuthModel($db))->findEmployeeContextById($employeeId);
        } catch (Throwable $exception) {
            error_log('[SDL PSR API] Employee context lookup failed: ' . get_class($exception));
            return $this->failure('Employee context is temporarily unavailable.', 503, 'employee_context_unavailable');
        }
        if ($employee === null) return $this->failure('Authenticated employee profile was not found.', 404, 'employee_not_found');
        return $this->success(['employee' => $employee], 'Authenticated employee profile.');
    }
}
