<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\NonFieldActivityModel;
use InvalidArgumentException;
use SDL\Framework\Controller;
use SDL\Framework\Database;
use SDL\Framework\Request;
use SDL\Framework\Response;
use Throwable;

final class NonFieldActivityController extends Controller
{
    public function options(Request $request): Response
    {
        try {
            $data = (new NonFieldActivityModel(Database::fromEnvironment()))
                ->activityOptions();
        } catch (Throwable $exception) {
            error_log(
                '[SDL PSR API] Non-field activity options failed: ' .
                get_class($exception) . ': ' . $exception->getMessage()
            );
            return $this->failure(
                'Activity options are temporarily unavailable.',
                503,
                'non_field_activity_options_unavailable'
            );
        }
        return $this->success($data, 'Non-field activity options returned.');
    }

    public function index(Request $request): Response
    {
        $employeeId = $this->employeeId($request);
        if ($employeeId === null) {
            return $this->failure('Authentication is required.', 401, 'unauthorized');
        }

        $activityId = $request->query()['activity_id'] ?? null;
        if ($activityId !== null &&
            (!is_string($activityId) || !ctype_digit($activityId) || (int) $activityId <= 0)) {
            return $this->failure('activity_id must be a positive integer.', 422, 'validation_failed');
        }

        try {
            $data = (new NonFieldActivityModel(Database::fromEnvironment()))
                ->listForEmployee($employeeId, $activityId === null ? null : (int) $activityId);
        } catch (Throwable $exception) {
            error_log(
                '[SDL PSR API] Non-field activity listing failed: ' .
                get_class($exception) . ': ' . $exception->getMessage()
            );
            return $this->failure(
                'Non-field activities are temporarily unavailable.',
                503,
                'non_field_activities_unavailable'
            );
        }
        return $this->success($data, 'Non-field activities returned.');
    }

    public function save(Request $request): Response
    {
        $employeeId = $this->employeeId($request);
        if ($employeeId === null) {
            return $this->failure('Authentication is required.', 401, 'unauthorized');
        }

        try {
            $data = (new NonFieldActivityModel(Database::fromEnvironment()))
                ->save(
                    $employeeId,
                    $this->loginId($request),
                    $request->body()
                );
        } catch (InvalidArgumentException $exception) {
            return $this->failure($exception->getMessage(), 422, 'validation_failed');
        } catch (Throwable $exception) {
            error_log(
                '[SDL PSR API] Non-field activity save failed: ' .
                get_class($exception) . ': ' . $exception->getMessage()
            );
            return $this->failure(
                'The non-field activity could not be submitted.',
                503,
                'non_field_activity_save_failed'
            );
        }
        return $this->success($data, 'Non-field activity sent for authorisation.', 201);
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
}
