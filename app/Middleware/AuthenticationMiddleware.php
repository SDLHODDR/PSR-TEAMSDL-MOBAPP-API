<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Models\AuthTokenModel;
use App\Models\EmployeeAuthModel;
use App\Services\TokenService;
use SDL\Framework\Database;
use SDL\Framework\MiddlewareInterface;
use SDL\Framework\Request;
use SDL\Framework\Response;
use Throwable;

final class AuthenticationMiddleware implements MiddlewareInterface
{
    public function process(Request $request, callable $next): Response
    {
        $header = trim((string) $request->header('authorization', ''));
        if ($header === '' || !preg_match('/^Bearer\s+([A-Za-z0-9_-]+)$/i', $header, $matches)) {
            return $this->unauthorized('A valid bearer access token is required.');
        }
        $rawToken = $matches[1];
        if (strlen($rawToken) !== 43) return $this->unauthorized('The access token is invalid or expired.');

        try {
            $db = Database::fromEnvironment();
            $token = (new AuthTokenModel($db))->findValidAccessToken((new TokenService())->hashToken($rawToken));
            if ($token === null) return $this->unauthorized('The access token is invalid or expired.');
            $employee = (new EmployeeAuthModel($db))->findActiveEmployeeById((int) $token['EMP_ID']);
            if ($employee === null) return $this->unauthorized('The access token is invalid or expired.');

            $request->setAttribute('authenticated_employee_id', (int) $employee['EMP_ID']);
            $request->setAttribute('authenticated_employee', [
                'login_id' => $employee['LOGIN_ID'] ?? $token['LOGIN_ID'] ?? null,
                'emp_id' => $employee['EMP_ID'] ?? $token['EMP_ID'] ?? null,
                'geo_id' => $employee['GEO_ID'] ?? null,
                'geo_level' => $employee['GEO_LEVEL'] ?? null,
            ]);
            $request->setAttribute('authenticated_token_id', $token['TOKEN_ID'] ?? null);
            $request->setAttribute('authenticated_family_id', rtrim((string) ($token['FAMILY_ID'] ?? '')));
            return $next($request);
        } catch (Throwable $exception) {
            error_log('[SDL PSR API] Access-token validation failed: ' . get_class($exception));
            return Response::json([
                'success' => false, 'message' => 'Authentication service is temporarily unavailable.',
                'error' => ['code' => 'authentication_service_unavailable'], 'meta' => ['version' => 'v1'],
            ], 503);
        }
    }

    private function unauthorized(string $message): Response
    {
        return Response::json([
            'success' => false, 'message' => $message,
            'error' => ['code' => 'unauthorized'], 'meta' => ['version' => 'v1'],
        ], 401, ['WWW-Authenticate' => 'Bearer realm="sdl-psr-api"']);
    }
}
