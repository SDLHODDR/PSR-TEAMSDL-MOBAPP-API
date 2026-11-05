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

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';

$allowedOrigins = array_values(array_filter(array_map(
    'trim',
    explode(',', (string) (getenv('CORS_ALLOWED_ORIGINS') ?: ''))
)));

$isLocalEnvironment = strtolower((string) (getenv('APP_ENV') ?: 'production')) === 'local';
$isLocalDevelopmentOrigin = preg_match(
    '#^https?://(localhost|127\.0\.0\.1|192\.168\.1\.2)(:\d+)?$#',
    $origin
) === 1;

if (($isLocalEnvironment && $isLocalDevelopmentOrigin) || in_array($origin, $allowedOrigins, true)) {
    header("Access-Control-Allow-Origin: {$origin}");
    header('Vary: Origin');
    header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
}

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

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
