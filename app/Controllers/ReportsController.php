<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\ReportsModel;
use InvalidArgumentException;
use SDL\Framework\Controller;
use SDL\Framework\Database;
use SDL\Framework\Request;
use SDL\Framework\Response;
use Throwable;

final class ReportsController extends Controller
{
    public function monthly(Request $request): Response
    {
        $employeeId = $this->employeeId($request);
        $month = $request->query()['month'] ?? null;
        if (!is_string($month)) {
            return $this->failure('month is required and must use YYYY-MM format.', 422, 'validation_failed');
        }

        try {
            $report = (new ReportsModel(Database::fromEnvironment()))
                ->monthly($employeeId, $month);
        } catch (InvalidArgumentException $exception) {
            return $this->failure($exception->getMessage(), 422, 'validation_failed');
        } catch (Throwable $exception) {
            error_log('[SDL PSR API] Monthly report lookup failed: ' . get_class($exception));
            return $this->failure('The monthly report is temporarily unavailable.', 503, 'monthly_report_unavailable');
        }

        return $this->success($report, 'Monthly report returned.');
    }

    public function daily(Request $request): Response
    {
        $employeeId = $this->employeeId($request);
        $date = $request->query()['date'] ?? null;
        if (!is_string($date)) {
            return $this->failure('date is required and must use YYYY-MM-DD format.', 422, 'validation_failed');
        }

        try {
            $report = (new ReportsModel(Database::fromEnvironment()))
                ->daily($employeeId, $date);
        } catch (InvalidArgumentException $exception) {
            return $this->failure($exception->getMessage(), 422, 'validation_failed');
        } catch (Throwable $exception) {
            error_log('[SDL PSR API] Daily report lookup failed: ' . get_class($exception));
            return $this->failure('The daily report is temporarily unavailable.', 503, 'daily_report_unavailable');
        }

        if ($report === null) {
            return $this->failure('A route report was not found for this date.', 404, 'daily_report_not_found');
        }

        return $this->success($report, 'Daily report returned.');
    }

    private function employeeId(Request $request): ?int
    {
        $id = $request->attribute('authenticated_employee_id');
        return is_int($id) && $id > 0 ? $id : null;
    }
}
