<?php

declare(strict_types=1);

namespace App\Controllers;

use SDL\Framework\Controller;
use SDL\Framework\Request;
use SDL\Framework\Response;

final class HealthController extends Controller
{
    public function show(Request $request): Response
    {
        return $this->success([
            'status' => 'ok',
            'service' => 'sdl-psr-api',
            'timestamp' => gmdate(DATE_ATOM),
            'php_version' => PHP_VERSION,
            'database' => 'not_checked_in_phase_1',
        ], 'API is healthy');
    }
}
