<?php

declare(strict_types=1);

namespace SDL\Framework;

final class RequestIdMiddleware implements MiddlewareInterface
{
    public function process(Request $request, callable $next): Response
    {
        $requestId = bin2hex(random_bytes(16));
        $response = $next($request);
        return $response->withHeader('X-Request-Id', $requestId);
    }
}
