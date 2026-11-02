<?php

declare(strict_types=1);

use SDL\Framework\Env;
use SDL\Framework\ErrorHandler;
use SDL\Framework\Request;
use SDL\Framework\Response;
use SDL\Framework\Router;

define('BASE_PATH', dirname(__DIR__));

require BASE_PATH . '/vendor/autoload.php';

Env::load(BASE_PATH . '/.env');

ErrorHandler::register(
    BASE_PATH . '/storage/logs',
    filter_var(getenv('APP_DEBUG') ?: 'true', FILTER_VALIDATE_BOOL)
);

$request = Request::fromGlobals();
$router = new Router();

/*
 * Register middleware that applies to every request here in Phase 1.
 * Example: $router->middleware(new RequestIdMiddleware());
 */
require BASE_PATH . '/app/Routes/api_v1.php';

try {
    $response = $router->dispatch($request);
} catch (Throwable $exception) {
    $response = ErrorHandler::response($exception);
}

$response->send();
