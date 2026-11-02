<?php

declare(strict_types=1);

namespace App\Controllers;

use SDL\Framework\Controller;
use SDL\Framework\Request;
use SDL\Framework\Response;

final class ExampleController extends Controller
{
    public function index(Request $request): Response
    {
        return $this->success([
            ['id' => 1, 'name' => 'Example PSR'],
            ['id' => 2, 'name' => 'Example Product'],
        ], 'Example records returned');
    }

    public function show(Request $request): Response
    {
        return $this->success([
            'id' => $request->routeParam('id'),
            'note' => 'This is sample data, not a database record.',
        ], 'Example record returned');
    }

    public function echoJson(Request $request): Response
    {
        return $this->success([
            'received' => $request->body(),
        ], 'JSON received successfully', 201);
    }
}
