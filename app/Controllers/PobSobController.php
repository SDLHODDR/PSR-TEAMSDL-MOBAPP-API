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
            error_log(
                '[SDL PSR API] POB/SOB lookup failed: ' .
                get_class($exception) . ': ' . $exception->getMessage()
            );
            return $this->failure('POB/SOB orders are temporarily unavailable.', 503, 'pob_sob_unavailable');
        }

        return $this->success($data, 'POB/SOB orders returned.');
    }

    public function options(Request $request): Response
    {
        $employeeId = $this->employeeId($request);
        if ($employeeId === null) {
            return $this->failure('Authentication is required.', 401, 'unauthorized');
        }
        $customerId = null;
        if (isset($request->query()['customer_id'])) {
            $customerId = filter_var(
                $request->query()['customer_id'],
                FILTER_VALIDATE_INT
            );
            if ($customerId === false || $customerId <= 0) {
                return $this->failure(
                    'customer_id must be a positive integer.',
                    422,
                    'validation_failed'
                );
            }
        }

        try {
            $data = (new PobSobModel(Database::fromEnvironment()))
                ->options($employeeId, $customerId);
        } catch (InvalidArgumentException $exception) {
            return $this->failure($exception->getMessage(), 422, 'validation_failed');
        } catch (Throwable $exception) {
            error_log(
                '[SDL PSR API] POB/SOB options lookup failed: ' .
                get_class($exception) . ': ' . $exception->getMessage()
            );
            return $this->failure('POB/SOB options are temporarily unavailable.', 503, 'pob_sob_options_unavailable');
        }

        return $this->success($data, 'POB/SOB options returned.');
    }

    public function order(Request $request): Response
    {
        $employeeId = $this->employeeId($request);
        if ($employeeId === null) {
            return $this->failure('Authentication is required.', 401, 'unauthorized');
        }
        $orderId = filter_var(
            $request->routeParam('orderId'),
            FILTER_VALIDATE_INT
        );
        $source = $request->query()['source'] ?? null;
        if ($orderId === false || $orderId <= 0 || !is_string($source)) {
            return $this->failure(
                'A valid order ID and source are required.',
                422,
                'validation_failed'
            );
        }

        try {
            $data = (new PobSobModel(Database::fromEnvironment()))
                ->order($employeeId, $source, $orderId);
        } catch (InvalidArgumentException $exception) {
            return $this->failure($exception->getMessage(), 422, 'validation_failed');
        } catch (Throwable $exception) {
            error_log('[SDL PSR API] POB/SOB detail lookup failed: ' . get_class($exception));
            return $this->failure('POB/SOB order details are temporarily unavailable.', 503, 'pob_sob_order_unavailable');
        }

        if ($data === null) {
            return $this->failure('The POB/SOB order was not found.', 404, 'pob_sob_order_not_found');
        }
        return $this->success($data, 'POB/SOB order details returned.');
    }

    public function save(Request $request): Response
    {
        $employeeId = $this->employeeId($request);
        if ($employeeId === null) {
            return $this->failure('Authentication is required.', 401, 'unauthorized');
        }
        $loginId = (string) ($request->attribute('authenticated_employee')['login_id'] ?? '');
        try {
            $data = (new PobSobModel(Database::fromEnvironment()))
                ->saveDraft($employeeId, $loginId, $request->body());
        } catch (InvalidArgumentException $exception) {
            return $this->failure($exception->getMessage(), 422, 'validation_failed');
        } catch (Throwable $exception) {
            error_log('[SDL PSR API] POB/SOB draft save failed: ' . get_class($exception));
            return $this->failure('The POB/SOB draft could not be saved.', 503, 'pob_sob_save_failed');
        }
        return $this->success($data, 'POB/SOB draft saved.');
    }

    public function confirm(Request $request): Response
    {
        $employeeId = $this->employeeId($request);
        if ($employeeId === null) {
            return $this->failure('Authentication is required.', 401, 'unauthorized');
        }
        $orderId = filter_var(
            $request->routeParam('orderId'),
            FILTER_VALIDATE_INT
        );
        if ($orderId === false || $orderId <= 0) {
            return $this->failure(
                'order_id must be a positive integer.',
                422,
                'validation_failed'
            );
        }

        try {
            $data = (new PobSobModel(Database::fromEnvironment()))
                ->confirm($employeeId, $orderId);
        } catch (InvalidArgumentException $exception) {
            return $this->failure($exception->getMessage(), 422, 'validation_failed');
        } catch (Throwable $exception) {
            error_log('[SDL PSR API] POB/SOB confirmation failed: ' . get_class($exception));
            return $this->failure('The POB/SOB order could not be confirmed.', 503, 'pob_sob_confirm_failed');
        }
        return $this->success($data, 'POB/SOB order confirmed.');
    }

    private function employeeId(Request $request): ?int
    {
        $employeeId = $request->attribute('authenticated_employee_id');
        return is_int($employeeId) && $employeeId > 0 ? $employeeId : null;
    }
}
