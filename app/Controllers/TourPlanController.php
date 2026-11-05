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
        $employeeId = $this->employeeId($request); $body = $request->body();
        // Accept both a flat request and the common {month, day: {...}} mobile shape.
        if (isset($body['day']) && is_array($body['day'])) $body = array_merge($body, $body['day']);
        try { $data = (new TourPlanModel(Database::fromEnvironment()))->saveDraft($employeeId, $this->loginId($request), $body); }
        catch (InvalidArgumentException $e) { return $this->failure($e->getMessage(), 422, 'validation_failed'); }
        catch (Throwable $e) { error_log('[SDL PSR API] Tour-plan draft save failed: ' . get_class($e)); return $this->failure('Tour-plan draft could not be saved.', 503, 'tour_plan_save_failed'); }
        return $this->success($data, 'Tour-plan day saved.');
    }
    public function submit(Request $request): Response
    {
        $employeeId = $this->employeeId($request); $month = (string) ($request->body()['month'] ?? '');
        try { $data = (new TourPlanModel(Database::fromEnvironment()))->submit($employeeId, $this->loginId($request), $month); }
        catch (InvalidArgumentException $e) { return $this->failure($e->getMessage(), 422, 'validation_failed'); }
        catch (Throwable $e) { error_log('[SDL PSR API] Tour-plan submit failed: ' . get_class($e)); return $this->failure('Tour plan could not be submitted.', 503, 'tour_plan_submit_failed'); }
        return $this->success($data, 'Tour plan submitted for authorization.');
    }
    private function employeeId(Request $r): int { $id = $r->attribute('authenticated_employee_id'); if (!is_int($id) || $id <= 0) throw new InvalidArgumentException('Authentication is required.'); return $id; }
    private function loginId(Request $r): string { return (string) ($r->attribute('authenticated_employee')['login_id'] ?? ''); }
}
