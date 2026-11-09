<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Services\TokenService;
use SDL\Framework\MiddlewareInterface;
use SDL\Framework\Request;
use SDL\Framework\Response;
use Throwable;

final class AuthenticationMiddleware implements MiddlewareInterface
{
    public function process(Request $request, callable $next): Response
    {
        $header = trim((string) $request->header('authorization', ''));
        if ($header === '' || !preg_match('/^Bearer\s+(.+)$/i', $header, $matches)) {
            return $this->unauthorized('A valid bearer access token is required.');
        }
        $rawToken = $matches[1];

        try {
            $verified = (new TokenService())->verifyAccessToken($rawToken);
            $claims = $verified['claims'];
            $employeeId = (int) $claims['sub'];

            $request->setAttribute('authenticated_employee_id', $employeeId);
            $request->setAttribute('authenticated_employee', [
                'login_id' => $claims['login_id'] ?? null,
                'emp_id' => $employeeId,
            ]);
            return $next($request);
        } catch (Throwable $exception) {
            return $this->unauthorized('The access token is invalid or expired.');
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
