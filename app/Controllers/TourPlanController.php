<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\TourPlanModel;
use InvalidArgumentException;
use SDL\Framework\Controller;
use SDL\Framework\Database;
use SDL\Framework\Request;
use SDL\Framework\Response;
use Throwable;

final class TourPlanController extends Controller
{
    public function activities(Request $request): Response
    {
        try {
            $data = (new TourPlanModel(Database::fromEnvironment()))->activityOptions();
        } catch (Throwable $e) {
            error_log('[SDL PSR API] Tour-plan activity lookup failed: ' . get_class($e));
            return $this->failure('Activity options are temporarily unavailable.', 503, 'tour_plan_activities_unavailable');
        }
        return $this->success($data, 'Tour-plan activities returned.');
    }

    public function routes(Request $request): Response
    {
        $employeeId = $this->employeeId($request);
        $date = (string) ($request->query()['date'] ?? '');
        try {
            $data = (new TourPlanModel(Database::fromEnvironment()))->routeOptions($employeeId, $date);
        } catch (InvalidArgumentException $e) {
            return $this->failure($e->getMessage(), 422, 'validation_failed');
        } catch (Throwable $e) {
            error_log('[SDL PSR API] Tour-plan route lookup failed: ' . get_class($e));
            return $this->failure('Route options are temporarily unavailable.', 503, 'tour_plan_routes_unavailable');
        }
        return $this->success($data, 'Tour-plan routes returned.');
    }

    public function index(Request $request): Response
    {
        $employeeId = $this->employeeId($request);
        $month = (string) ($request->query()['month'] ?? (new \DateTimeImmutable('now'))->format('Y-m'));
        try { $data = (new TourPlanModel(Database::fromEnvironment()))->listByMonth($employeeId, $month); }
        catch (InvalidArgumentException $e) { return $this->failure($e->getMessage(), 422, 'validation_failed'); }
        catch (Throwable $e) { error_log('[SDL PSR API] Tour-plan lookup failed: ' . get_class($e)); return $this->failure('Tour plans are temporarily unavailable.', 503, 'tour_plan_unavailable'); }
        return $this->success($data, 'Tour plans returned.');
    }
    public function draft(Request $request): Response
    {
        $employeeId = $this->employeeId($request); 
        $body = $request->body();
        // Accept both a flat request and the common {month, day: {...}} mobile shape.
        if (isset($body['day']) && is_array($body['day'])) 
            $body = array_merge($body, $body['day']);
        
        try { 
            $data = ( new TourPlanModel(Database::fromEnvironment()))
                ->saveDraft(
                    $employeeId, 
                    $this->loginId($request), 
                    $body
                );
        }
        catch (InvalidArgumentException $e) { 
            return $this->failure($e->getMessage(), 422, 'validation_failed'); 
        }
        catch (Throwable $e) { 
            error_log('[SDL PSR API] Tour-plan draft save failed: ' . get_class($e)); 
            return $this->failure('Tour-plan draft could not be saved.', 503, 'tour_plan_save_failed'); 
        }
        return $this->success($data, 'Tour-plan day saved.');
    }
    public function submit(Request $request): Response
    {
        $employeeId = $this->employeeId($request);
        $body = $request->body();
        $month = (string) ($body['month'] ?? '');
        $days = $body['days'] ?? null;
        if ($days !== null && !is_array($days)) {
            return $this->failure('days must be an array.', 422, 'validation_failed');
        }
        try { $data = (new TourPlanModel(Database::fromEnvironment()))->submit($employeeId, $this->loginId($request), $month, $days); }
        catch (InvalidArgumentException $e) { return $this->failure($e->getMessage(), 422, 'validation_failed'); }
        catch (Throwable $e) { error_log('[SDL PSR API] Tour-plan submit failed: ' . get_class($e)); return $this->failure('Tour plan could not be submitted.', 503, 'tour_plan_submit_failed'); }
        return $this->success($data, 'Tour plan submitted for authorization.');
    }
    private function employeeId(Request $r): int { $id = $r->attribute('authenticated_employee_id'); if (!is_int($id) || $id <= 0) throw new InvalidArgumentException('Authentication is required.'); return $id; }
    private function loginId(Request $r): string { return (string) ($r->attribute('authenticated_employee')['login_id'] ?? ''); }
}
