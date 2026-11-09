<?php

declare(strict_types=1);

use App\Controllers\AuthController;
use App\Controllers\DatabaseHealthController;
use App\Controllers\DashboardController;
use App\Controllers\ExampleController;
use App\Controllers\HealthController;
use App\Controllers\TourPlanController;
use App\Middleware\AuthenticationMiddleware;
use App\Middleware\EmployeeAccessMiddleware;
use SDL\Framework\RequestIdMiddleware;

/** @var \SDL\Framework\Router $router */
$router->middleware(new RequestIdMiddleware());

$router->get('/api/v1/health', [HealthController::class, 'show']);
$router->get('/api/v1/health/db', [DatabaseHealthController::class, 'show']);
$router->post('/api/v1/auth/login', [AuthController::class, 'login']);
$router->post('/api/v1/auth/logout', [AuthController::class, 'logout'], [new AuthenticationMiddleware()]);
$router->get('/api/v1/auth/me', [AuthController::class, 'me'], [new AuthenticationMiddleware()]);
$businessMiddleware = [new AuthenticationMiddleware(), new EmployeeAccessMiddleware()];
$router->get('/api/v1/dashboard', [DashboardController::class, 'index'], $businessMiddleware);
$router->get('/api/v1/tour-plans/activities', [TourPlanController::class, 'activities'], $businessMiddleware);
$router->get('/api/v1/tour-plans/routes', [TourPlanController::class, 'routes'], $businessMiddleware);
$router->get('/api/v1/tour-plans', [TourPlanController::class, 'index'], $businessMiddleware);
$router->post('/api/v1/tour-plans/draft', [TourPlanController::class, 'draft'], $businessMiddleware);
$router->post('/api/v1/tour-plans/submit', [TourPlanController::class, 'submit'], $businessMiddleware);
$router->get('/api/v1/examples', [ExampleController::class, 'index']);
$router->get('/api/v1/examples/{id}', [ExampleController::class, 'show']);
$router->post('/api/v1/examples', [ExampleController::class, 'echoJson']);
