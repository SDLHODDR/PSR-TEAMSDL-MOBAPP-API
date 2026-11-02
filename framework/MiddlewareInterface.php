<?php

declare(strict_types=1);

namespace SDL\Framework;

interface MiddlewareInterface
{
    public function process(Request $request, callable $next): Response;
}
