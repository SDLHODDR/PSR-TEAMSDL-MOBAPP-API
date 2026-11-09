<?php

declare(strict_types=1);

namespace App\Controllers;

// ini_set('display_errors', 1);
// ini_set('display_startup_errors', 1);
// error_reporting(E_ALL);

use App\Models\EmployeeAuthModel;
use App\Services\AuthService;
use App\Services\LegacyPasswordEncoder;
use App\Services\TokenService;
use SDL\Framework\Controller;
use SDL\Framework\Database;
use SDL\Framework\Request;
use SDL\Framework\Response;
use SDL\Framework\Helpers;

use Throwable;

final class AuthController extends Controller
{
    public function login(Request $request): Response
    {
        $body = $request->body();
        $username = $body['username'] ?? null;
        $password = $body['password'] ?? null;

        //Helpers::printArr('--------Request-------', [$body, $username, $password]);
        $errors = [];
        if (!is_string($username) || trim($username) === '') $errors['username'] = ['Username is required.'];
        if (!is_string($password) || $password === '') $errors['password'] = ['Password is required.'];
        if ($errors !== []) return $this->failure('Validation failed.', 422, 'validation_failed', $errors);
        $username = trim($username);
        if (strlen($username) > 100 || strlen($password) > 1024) return $this->failure('Invalid login credentials.', 401, 'invalid_credentials');

        try {
            $db = Database::fromEnvironment();
            
            $service = new AuthService(new EmployeeAuthModel($db), new LegacyPasswordEncoder(), new TokenService());
            $result = $service->loginAndIssueTokens($username, $password, $request->ip());
            //Helpers::printArr('----Auth Employee Results ----', $result);
        } catch (Throwable $exception) {
            error_log('[SDL PSR API] Login/token issuance failed: ' . get_class($exception));
            return $this->failure('Login service is temporarily unavailable.', 503, 'authentication_service_unavailable');
        }
        if ($result === null) return $this->failure('Invalid login credentials.', 401, 'invalid_credentials');
        return $this->success([
            'token_type' => 'Bearer',
            'access_token' => $result['access_token'], 'expires_in' => $result['expires_in'],
            'expires_at' => $result['expires_at'], 'access_expires_at' => $result['access_expires_at'],
        ], 'Login successful.');
    }

    /** Stateless logout: the client discards the JWT; it remains valid until expiry. */
    public function logout(Request $request): Response
    {
        return $this->success(['logged_out' => true], 'Logout successful. The access token remains valid until expiry.');
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
