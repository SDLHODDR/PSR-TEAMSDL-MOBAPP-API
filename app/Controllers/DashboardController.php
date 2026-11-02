<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\DashboardModel;
use SDL\Framework\Controller;
use SDL\Framework\Database;
use SDL\Framework\Request;
use SDL\Framework\Response;
use Throwable;

final class DashboardController extends Controller
{
    public function index(Request $request): Response
    {
        $employeeId = $request->attribute('authenticated_employee_id');
        if (!is_int($employeeId) || $employeeId <= 0) {
            return $this->failure('Authentication is required.', 401, 'unauthorized');
        }

        try {
            $dashboard = (new DashboardModel(Database::fromEnvironment()))
                ->findDashboardByEmployeeId($employeeId);
        } catch (Throwable $exception) {
            error_log('[SDL PSR API] Dashboard lookup failed: ' . get_class($exception));
            return $this->failure('Dashboard is temporarily unavailable.', 503, 'dashboard_unavailable');
        }

        if ($dashboard === []) {
            return $this->failure('Authenticated employee dashboard was not found.', 404, 'dashboard_not_found');
        }

        return $this->success($dashboard, 'Dashboard information returned.');
    }
}
