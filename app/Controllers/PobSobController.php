<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\PobSobModel;
use InvalidArgumentException;
use SDL\Framework\Controller;
use SDL\Framework\Database;
use SDL\Framework\Request;
use SDL\Framework\Response;
use Throwable;

final class PobSobController extends Controller
{
    public function index(Request $request): Response
    {
        $employeeId = $request->attribute('authenticated_employee_id');
        if (!is_int($employeeId) || $employeeId <= 0) {
            return $this->failure('Authentication is required.', 401, 'unauthorized');
        }

        $month = $request->query()['month'] ?? null;
        if (!is_string($month)) {
            return $this->failure('month is required and must use YYYY-MM format.', 422, 'validation_failed');
        }

        try {
            $data = (new PobSobModel(Database::fromEnvironment()))
                ->ordersByMonth($employeeId, $month);
        } catch (InvalidArgumentException $exception) {
            return $this->failure($exception->getMessage(), 422, 'validation_failed');
        } catch (Throwable $exception) {
            error_log('[SDL PSR API] POB/SOB lookup failed: ' . get_class($exception));
            return $this->failure('POB/SOB orders are temporarily unavailable.', 503, 'pob_sob_unavailable');
        }

        return $this->success($data, 'POB/SOB orders returned.');
    }
}
