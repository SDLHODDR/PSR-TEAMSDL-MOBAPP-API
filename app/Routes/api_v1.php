<?php

declare(strict_types=1);

use App\Controllers\AuthController;
use App\Controllers\DatabaseHealthController;
use App\Controllers\DashboardController;
use App\Controllers\ExampleController;
use App\Controllers\HealthController;
use App\Middleware\AuthenticationMiddleware;
use SDL\Framework\RequestIdMiddleware;

/** @var \SDL\Framework\Router $router */
$router->middleware(new RequestIdMiddleware());

$router->get('/api/v1/health', [HealthController::class, 'show']);
$router->get('/api/v1/health/db', [DatabaseHealthController::class, 'show']);
$router->post('/api/v1/auth/login', [AuthController::class, 'login']);
$router->post('/api/v1/auth/refresh', [AuthController::class, 'refresh']);
$router->post('/api/v1/auth/logout', [AuthController::class, 'logout'], [new AuthenticationMiddleware()]);
$router->get('/api/v1/auth/me', [AuthController::class, 'me'], [new AuthenticationMiddleware()]);
$router->get('/api/v1/dashboard', [DashboardController::class, 'index'], [new AuthenticationMiddleware()]);
$router->get('/api/v1/examples', [ExampleController::class, 'index']);
$router->get('/api/v1/examples/{id}', [ExampleController::class, 'show']);
$router->post('/api/v1/examples', [ExampleController::class, 'echoJson']);
