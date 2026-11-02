<?php

declare(strict_types=1);

namespace App\Controllers;

use SDL\Framework\Controller;
use SDL\Framework\Database;
use SDL\Framework\DatabaseException;
use SDL\Framework\HttpException;
use SDL\Framework\Request;
use SDL\Framework\Response;
use Throwable;

final class DatabaseHealthController extends Controller
{
    public function show(Request $request): Response
    {
        try {
            $database = Database::fromEnvironment();
            $healthy = $database->ping();
            if (!$healthy) {
                throw new DatabaseException('Oracle health query returned no expected result.');
            }

            return $this->success([
                'database' => 'oracle',
                'status' => 'connected',
            ], 'Oracle database connection is healthy');
        } catch (Throwable $exception) {
            error_log('[SDL PSR API] Database health check failed: ' . get_class($exception) . ': ' . $exception->getMessage());
            return $this->failure(
                'Oracle database is unavailable',
                503,
                'database_unavailable'
            );
        }
    }
}
