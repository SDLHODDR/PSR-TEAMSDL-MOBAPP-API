<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\DayPlanModel;
use InvalidArgumentException;
use SDL\Framework\Controller;
use SDL\Framework\Database;
use SDL\Framework\Request;
use SDL\Framework\Response;
use Throwable;

final class DayPlanController extends Controller
{
    public function index(Request $request): Response
    {
        $employeeId = $this->employeeId($request);
        $date = $request->query()['date'] ?? (new \DateTimeImmutable('today'))->format('Y-m-d');
        if (!is_string($date)) {
            return $this->failure('date must use YYYY-MM-DD format.', 422, 'validation_failed');
        }

        try {
            $data = (new DayPlanModel(Database::fromEnvironment()))->load($employeeId, $date);
        } catch (InvalidArgumentException $exception) {
            return $this->failure($exception->getMessage(), 422, 'validation_failed');
        } catch (Throwable $exception) {
            error_log(
                '[SDL PSR API] Day-plan lookup failed: ' .
                get_class($exception) . ' - ' . $exception->getMessage()
            );
            return $this->failure('The day plan is temporarily unavailable.', 503, 'day_plan_unavailable');
        }
        return $this->success($data, 'Day plan returned.');
    }

    public function options(Request $request): Response
    {
        $employeeId = $this->employeeId($request);
        $date = $request->query()['date'] ?? (new \DateTimeImmutable('today'))->format('Y-m-d');
        if (!is_string($date)) {
            return $this->failure('date must use YYYY-MM-DD format.', 422, 'validation_failed');
        }

        try {
            $data = (new DayPlanModel(Database::fromEnvironment()))->options($employeeId, $date);
        } catch (InvalidArgumentException $exception) {
            return $this->failure($exception->getMessage(), 422, 'validation_failed');
        } catch (Throwable $exception) {
            error_log('[SDL PSR API] Day-plan options lookup failed: ' . get_class($exception));
            return $this->failure('Day-plan options are temporarily unavailable.', 503, 'day_plan_options_unavailable');
        }
        return $this->success($data, 'Day-plan options returned.');
    }

    public function routeCustomers(Request $request): Response
    {
        $employeeId = $this->employeeId($request);
        $planId = $this->routeId($request, 'planId');
        $routeId = $this->routeId($request, 'routeId');
        if ($planId === null || $routeId === null) {
            return $this->failure('day_plan_id and route_id must be positive integers.', 422, 'validation_failed');
        }

        try {
            $data = (new DayPlanModel(Database::fromEnvironment()))
                ->listedCustomersForRoute($employeeId, $planId, $routeId);
        } catch (InvalidArgumentException $exception) {
            return $this->failure($exception->getMessage(), 422, 'validation_failed');
        } catch (Throwable $exception) {
            error_log('[SDL PSR API] Day-plan route-customer lookup failed: ' . get_class($exception));
            return $this->failure('Route customers are temporarily unavailable.', 503, 'day_plan_customers_unavailable');
        }
        if ($data === null) {
            return $this->failure('The day plan was not found.', 404, 'day_plan_not_found');
        }
        return $this->success($data, 'Day-plan route customers returned.');
    }

    public function deleteListedCustomer(Request $request): Response
    {
        $employeeId = $this->employeeId($request);
        $planId = $this->routeId($request, 'planId');
        $customerId = $this->routeId($request, 'customerId');
        if ($planId === null || $customerId === null) {
            return $this->failure('day_plan_id and customer_id must be positive integers.', 422, 'validation_failed');
        }
        try {
            $data = (new DayPlanModel(Database::fromEnvironment()))
                ->deleteListedCustomerForEmployee($employeeId, $planId, $customerId);
        } catch (InvalidArgumentException $exception) {
            return $this->failure($exception->getMessage(), 422, 'validation_failed');
        } catch (Throwable $exception) {
            error_log('[SDL PSR API] Listed-customer delete failed: ' . get_class($exception));
            return $this->failure('The listed customer could not be removed.', 503, 'listed_customer_delete_failed');
        }
        return $this->success($data, 'Listed customer removed.');
    }

    public function customerActivity(Request $request): Response
    {
        $employeeId = $this->employeeId($request);
        $planId = $this->routeId($request, 'planId');
        $query = $request->query();
        $customerId = $this->optionalId($query['customer_id'] ?? null);
        $unlistedCustomerId = $this->optionalId($query['unlisted_customer_id'] ?? null);
        $activity = $query['activity'] ?? null;
        if ($planId === null || !is_string($activity) ||
            (($query['customer_id'] ?? null) !== null && $customerId === null) ||
            (($query['unlisted_customer_id'] ?? null) !== null && $unlistedCustomerId === null) ||
            (($customerId === null) === ($unlistedCustomerId === null))) {
            return $this->failure('A valid day plan, customer, and activity are required.', 422, 'validation_failed');
        }

        try {
            $data = (new DayPlanModel(Database::fromEnvironment()))->customerActivity(
                $employeeId,
                $planId,
                $customerId,
                $unlistedCustomerId,
                $activity
            );
        } catch (InvalidArgumentException $exception) {
            return $this->failure($exception->getMessage(), 422, 'validation_failed');
        } catch (Throwable $exception) {
            error_log('[SDL PSR API] Day-plan activity lookup failed: ' . get_class($exception));
            return $this->failure('Customer activity is temporarily unavailable.', 503, 'day_plan_activity_unavailable');
        }
        if ($data === null) {
            return $this->failure('The selected customer was not found in this day plan.', 404, 'day_plan_customer_not_found');
        }
        return $this->success($data, 'Customer activity returned.');
    }

    public function saveDraft(Request $request): Response
    {
        $employeeId = $this->employeeId($request);
        try {
            $data = (new DayPlanModel(Database::fromEnvironment()))
                ->saveDraft($employeeId, $this->loginId($request), $request->body());
        } catch (InvalidArgumentException $exception) {
            return $this->failure($exception->getMessage(), 422, 'validation_failed');
        } catch (Throwable $exception) {
            error_log('[SDL PSR API] Day-plan save failed: ' . get_class($exception));
            return $this->failure('The day plan could not be saved.', 503, 'day_plan_save_failed');
        }
        return $this->success($data, 'Day plan saved.', 201);
    }

    public function saveOpenRequest(Request $request): Response
    {
        $employeeId = $this->employeeId($request);
        try {
            $data = (new DayPlanModel(Database::fromEnvironment()))
                ->saveOpenRequest($employeeId, $request->body());
        } catch (InvalidArgumentException $exception) {
            return $this->failure($exception->getMessage(), 422, 'validation_failed');
        } catch (Throwable $exception) {
            error_log('[SDL PSR API] Delayed-reporting request failed: ' . get_class($exception));
            return $this->failure('The delayed-reporting request could not be saved.', 503, 'open_request_save_failed');
        }
        return $this->success($data, 'Delayed-reporting request submitted.', 201);
    }

    public function addListedCustomers(Request $request): Response
    {
        $employeeId = $this->employeeId($request);
        try {
            $data = (new DayPlanModel(Database::fromEnvironment()))
                ->addListedCustomers($employeeId, $this->loginId($request), $request->body());
        } catch (InvalidArgumentException $exception) {
            return $this->failure($exception->getMessage(), 422, 'validation_failed');
        } catch (Throwable $exception) {
            error_log('[SDL PSR API] Day-plan customer save failed: ' . get_class($exception));
            return $this->failure('The selected customers could not be saved.', 503, 'day_plan_customer_save_failed');
        }
        return $this->success($data, 'Customers added to the day plan.');
    }

    public function addUnlistedCustomer(Request $request): Response
    {
        $employeeId = $this->employeeId($request);
        try {
            $data = (new DayPlanModel(Database::fromEnvironment()))
                ->addUnlistedCustomer($employeeId, $this->loginId($request), $request->body());
        } catch (InvalidArgumentException $exception) {
            return $this->failure($exception->getMessage(), 422, 'validation_failed');
        } catch (Throwable $exception) {
            error_log('[SDL PSR API] Day-plan unlisted-customer save failed: ' . get_class($exception));
            return $this->failure('The unlisted customer could not be saved.', 503, 'day_plan_unlisted_save_failed');
        }
        return $this->success($data, 'Unlisted customer added.', 201);
    }

    public function saveCustomerActivity(Request $request): Response
    {
        $employeeId = $this->employeeId($request);
        try {
            $data = (new DayPlanModel(Database::fromEnvironment()))
                ->saveCustomerActivity($employeeId, $this->loginId($request), $request->body());
        } catch (InvalidArgumentException $exception) {
            return $this->failure($exception->getMessage(), 422, 'validation_failed');
        } catch (Throwable $exception) {
            error_log('[SDL PSR API] Day-plan activity save failed: ' . get_class($exception));
            return $this->failure('Customer activity could not be saved.', 503, 'day_plan_activity_save_failed');
        }
        return $this->success($data, 'Customer activity saved.');
    }

    public function submit(Request $request): Response
    {
        $employeeId = $this->employeeId($request);
        $planId = filter_var($request->body()['day_plan_id'] ?? null, FILTER_VALIDATE_INT);
        if ($planId === false || $planId === null || $planId <= 0) {
            return $this->failure('day_plan_id must be a positive integer.', 422, 'validation_failed');
        }

        try {
            $data = (new DayPlanModel(Database::fromEnvironment()))
                ->submit($employeeId, $this->loginId($request), $planId);
        } catch (InvalidArgumentException $exception) {
            return $this->failure($exception->getMessage(), 422, 'validation_failed');
        } catch (Throwable $exception) {
            error_log('[SDL PSR API] Day-plan submit failed: ' . get_class($exception));
            return $this->failure('The day plan could not be submitted.', 503, 'day_plan_submit_failed');
        }
        return $this->success($data, 'Day plan submitted for authorization.');
    }

    private function employeeId(Request $request): int
    {
        $id = $request->attribute('authenticated_employee_id');
        if (!is_int($id) || $id <= 0) {
            throw new InvalidArgumentException('Authentication is required.');
        }
        return $id;
    }

    private function loginId(Request $request): string
    {
        return trim((string) ($request->attribute('authenticated_employee')['login_id'] ?? ''));
    }

    private function routeId(Request $request, string $name): ?int
    {
        return $this->optionalId($request->routeParam($name));
    }

    private function optionalId(mixed $value): ?int
    {
        $id = filter_var($value, FILTER_VALIDATE_INT);
        return $id === false || $id === null || $id <= 0 ? null : $id;
    }
}
