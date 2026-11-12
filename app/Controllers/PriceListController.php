<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\PriceListModel;
use SDL\Framework\Controller;
use SDL\Framework\Database;
use SDL\Framework\Request;
use SDL\Framework\Response;
use Throwable;

final class PriceListController extends Controller
{
    public function index(Request $request): Response
    {
        $employeeId = $request->attribute('authenticated_employee_id');
        if (!is_int($employeeId) || $employeeId <= 0) {
            return $this->failure('Authentication is required.', 401, 'unauthorized');
        }

        try {
            $catalogue = (new PriceListModel(Database::fromEnvironment()))
                ->catalogueForEmployee($employeeId);
        } catch (Throwable $exception) {
            error_log('[SDL PSR API] Price-list lookup failed: ' . get_class($exception));
            return $this->failure(
                'The price list is temporarily unavailable.',
                503,
                'price_list_unavailable'
            );
        }

        return $this->success($catalogue, 'Price list returned.');
    }
}
