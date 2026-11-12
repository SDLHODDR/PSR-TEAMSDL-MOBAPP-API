<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\RouteCooldownException;
use App\Models\RouteModel;
use InvalidArgumentException;
use SDL\Framework\Controller;
use SDL\Framework\Database;
use SDL\Framework\Request;
use SDL\Framework\Response;
use Throwable;

final class RouteController extends Controller
{
    public function index(Request $request): Response
    {
        $employeeId = $this->employeeId($request);
        
        if ($employeeId === null) {
            return $this->failure('Authentication is required.', 401, 'unauthorized');
        }

        try {
            $data = (new RouteModel(Database::fromEnvironment()))->routes($employeeId);
        } catch (InvalidArgumentException $exception) {
            return $this->failure($exception->getMessage(), 422, 'validation_failed');
        } catch (Throwable $exception) {
            error_log('[SDL PSR API] Route lookup failed: ' . get_class($exception));
            return $this->failure('Routes are temporarily unavailable.', 503, 'routes_unavailable');
        }
        return $this->success($data, 'Routes returned.');
    }

    public function customers(Request $request): Response
    {
        $routeId = $this->routeId($request);
        $employeeId = $this->employeeId($request);
        if ($routeId === null) {
            return $this->failure('route_id must be a positive integer.', 422, 'validation_failed');
        }
        if ($employeeId === null) {
            return $this->failure('Authentication is required.', 401, 'unauthorized');
        }

        try {
            $data = (new RouteModel(Database::fromEnvironment()))->customers($employeeId, $routeId);
        } catch (InvalidArgumentException $exception) {
            return $this->failure($exception->getMessage(), 422, 'validation_failed');
        } catch (Throwable $exception) {
            error_log('[SDL PSR API] Route customer lookup failed: ' . get_class($exception));
            return $this->failure('Route customers are temporarily unavailable.', 503, 'route_customers_unavailable');
        }
        if ($data === null) {
            return $this->failure('The requested route was not found.', 404, 'route_not_found');
        }
        return $this->success($data, 'Route customers returned.');
    }

    public function customer(Request $request): Response
    {
        $ids = $this->ids($request);
        if ($ids === null) {
            return $this->failure('route_id and customer_id must be positive integers.', 422, 'validation_failed');
        }
        $employeeId = $this->employeeId($request);
        if ($employeeId === null) {
            return $this->failure('Authentication is required.', 401, 'unauthorized');
        }

        try {
            $customer = (new RouteModel(Database::fromEnvironment()))
                ->customer($employeeId, $ids['route_id'], $ids['customer_id']);
        } catch (Throwable $exception) {
            error_log('[SDL PSR API] Route customer detail lookup failed: ' . get_class($exception));
            return $this->failure('Customer details are temporarily unavailable.', 503, 'route_customer_unavailable');
        }
        if ($customer === null) {
            return $this->failure('The requested route customer was not found.', 404, 'route_customer_not_found');
        }
        return $this->success(['customer' => $customer], 'Customer details returned.');
    }

    public function updateCustomer(Request $request): Response
    {
        $ids = $this->ids($request);
        if ($ids === null) {
            return $this->failure('route_id and customer_id must be positive integers.', 422, 'validation_failed');
        }
        $employeeId = $this->employeeId($request);
        if ($employeeId === null) {
            return $this->failure('Authentication is required.', 401, 'unauthorized');
        }

        try {
            $customer = (new RouteModel(Database::fromEnvironment()))->updateCustomer(
                $employeeId,
                $ids['route_id'],
                $ids['customer_id'],
                $request->body()
            );
        } catch (InvalidArgumentException $exception) {
            return $this->failure($exception->getMessage(), 422, 'validation_failed');
        } catch (Throwable $exception) {
            error_log('[SDL PSR API] Route customer update failed: ' . get_class($exception));
            return $this->failure('Customer details could not be saved.', 503, 'route_customer_save_failed');
        }
        if ($customer === null) {
            return $this->failure('The requested route customer was not found.', 404, 'route_customer_not_found');
        }
        return $this->success(['customer' => $customer], 'Customer details saved.');
    }

    public function requestConsentOtp(Request $request): Response
    {
        $ids = $this->ids($request);
        if ($ids === null) {
            return $this->failure('route_id and customer_id must be positive integers.', 422, 'validation_failed');
        }
        $employeeId = $this->employeeId($request);
        if ($employeeId === null) {
            return $this->failure('Authentication is required.', 401, 'unauthorized');
        }
        if (($request->body()['consent'] ?? false) !== true) {
            return $this->failure('Customer consent is required before requesting an OTP.', 422, 'consent_required');
        }

        try {
            $data = (new RouteModel(Database::fromEnvironment()))->requestConsentOtp(
                $employeeId,
                $this->loginId($request),
                $ids['route_id'],
                $ids['customer_id']
            );
        } catch (RouteCooldownException $exception) {
            return $this->failure(
                'Please wait before requesting another OTP.',
                429,
                'otp_cooldown',
                ['retry_after_seconds' => $exception->retryAfterSeconds]
            );
        } catch (InvalidArgumentException $exception) {
            return $this->failure($exception->getMessage(), 422, 'validation_failed');
        } catch (Throwable $exception) {
            error_log('[SDL PSR API] Consent OTP queue failed: ' . get_class($exception));
            return $this->failure('The consent OTP could not be queued.', 503, 'consent_otp_unavailable');
        }
        if ($data === null) {
            return $this->failure('The requested route customer was not found.', 404, 'route_customer_not_found');
        }
        return $this->success($data, 'Consent OTP queued for delivery.');
    }

    public function verifyConsentOtp(Request $request): Response
    {
        $ids = $this->ids($request);
        if ($ids === null) {
            return $this->failure('route_id and customer_id must be positive integers.', 422, 'validation_failed');
        }
        $employeeId = $this->employeeId($request);
        if ($employeeId === null) {
            return $this->failure('Authentication is required.', 401, 'unauthorized');
        }
        $otp = $request->body()['otp'] ?? null;
        if (!is_string($otp) || !preg_match('/^\d{4}$/', $otp)) {
            return $this->failure('otp must contain exactly four digits.', 422, 'validation_failed');
        }

        try {
            $verified = (new RouteModel(Database::fromEnvironment()))->verifyConsentOtp(
                $employeeId,
                $this->loginId($request),
                $ids['route_id'],
                $ids['customer_id'],
                $otp
            );
        } catch (InvalidArgumentException $exception) {
            return $this->failure($exception->getMessage(), 422, 'validation_failed');
        } catch (Throwable $exception) {
            error_log('[SDL PSR API] Consent OTP verification failed: ' . get_class($exception));
            return $this->failure('The consent OTP could not be verified.', 503, 'consent_otp_verification_failed');
        }
        if ($verified === null) {
            return $this->failure('The requested route customer was not found.', 404, 'route_customer_not_found');
        }
        if (!$verified) {
            return $this->failure('The OTP is incorrect or has expired.', 422, 'invalid_otp');
        }
        return $this->success(['verified' => true], 'Customer consent verified.');
    }

    private function employeeId(Request $request): ?int
    {
        $id = $request->attribute('authenticated_employee_id');
        return is_int($id) && $id > 0 ? $id : null;
    }

    private function loginId(Request $request): string
    {
        return trim((string) ($request->attribute('authenticated_employee')['login_id'] ?? ''));
    }

    private function routeId(Request $request): ?int
    {
        return $this->positiveId($request->routeParam('routeId'));
    }

    private function ids(Request $request): ?array
    {
        $routeId = $this->routeId($request);
        $customerId = $this->positiveId($request->routeParam('customerId'));
        return $routeId === null || $customerId === null
            ? null
            : ['route_id' => $routeId, 'customer_id' => $customerId];
    }

    private function positiveId(mixed $value): ?int
    {
        if (!is_string($value) && !is_int($value)) {
            return null;
        }
        $text = (string) $value;
        return ctype_digit($text) && (int) $text > 0 ? (int) $text : null;
    }
}
