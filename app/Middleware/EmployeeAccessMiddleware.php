<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Models\EmployeeAuthModel;
use SDL\Framework\Database;
use SDL\Framework\MiddlewareInterface;
use SDL\Framework\Request;
use SDL\Framework\Response;
use Throwable;

/** Business access check kept separate from stateless JWT verification. */
final class EmployeeAccessMiddleware implements MiddlewareInterface
{
    public function process(Request $request, callable $next): Response
    {
        $employeeId = $request->attribute('authenticated_employee_id');
        if (!is_int($employeeId) || $employeeId <= 0) return $this->unauthorized();

        try {
            $employee = (new EmployeeAuthModel(Database::fromEnvironment()))->findActiveEmployeeById($employeeId);
        } catch (Throwable $exception) {
            error_log('[SDL PSR API] Employee access check failed: ' . get_class($exception));
            return Response::json([
                'success' => false,
                'message' => 'Employee access service is temporarily unavailable.',
                'error' => ['code' => 'employee_access_unavailable'],
                'meta' => ['version' => 'v1'],
            ], 503);
        }

        return $employee === null ? $this->unauthorized() : $next($request);
    }

    private function unauthorized(): Response
    {
        return Response::json([
            'success' => false,
            'message' => 'The authenticated employee is not authorized for this resource.',
            'error' => ['code' => 'employee_not_authorized'],
            'meta' => ['version' => 'v1'],
        ], 403);
    }
}
