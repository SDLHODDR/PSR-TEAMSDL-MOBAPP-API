<?php

declare(strict_types=1);

namespace SDL\Framework;

abstract class Controller
{
    protected function success(mixed $data = null, string $message = 'OK', int $status = 200): Response
    {
        return Response::json([
            'success' => true,
            'message' => $message,
            'data' => $data,
            'meta' => ['version' => 'v1'],
        ], $status);
    }

    protected function failure(string $message, int $status, string $code, array $details = []): Response
    {
        $payload = ['success' => false, 'message' => $message, 'error' => ['code' => $code]];
        if ($details !== []) {
            $payload['error']['details'] = $details;
        }
        return Response::json($payload, $status);
    }
}
