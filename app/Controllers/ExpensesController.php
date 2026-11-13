<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\ExpensesModel;
use SDL\Framework\Controller;
use SDL\Framework\Database;
use SDL\Framework\Request;
use SDL\Framework\Response;
use Throwable;

final class ExpensesController extends Controller
{
    public function index(Request $request): Response
    {
        $employeeId = $request->attribute('authenticated_employee_id');
        if (!is_int($employeeId) || $employeeId <= 0) {
            return $this->failure('Authentication is required.', 401, 'unauthorized');
        }

        try {
            $expenses = (new ExpensesModel(Database::fromEnvironment()))
                ->lastThreeCompletedMonths($employeeId);
        } catch (Throwable $exception) {
            error_log('[SDL PSR API] Expenses lookup failed: ' . get_class($exception));
            return $this->failure(
                'Expenses are temporarily unavailable.',
                503,
                'expenses_unavailable'
            );
        }

        return $this->success($expenses, 'Expenses returned.');
    }
}
