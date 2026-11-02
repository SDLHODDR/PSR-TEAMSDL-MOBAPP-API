<?php

declare(strict_types=1);

namespace SDL\Framework;

use RuntimeException;

final class HttpException extends RuntimeException
{
    public function __construct(
        public readonly int $statusCode,
        string $message,
        public readonly string $errorCode = 'http_error',
        private readonly array $details = []
    ) {
        parent::__construct($message);
    }

    public function details(): array { return $this->details; }
}
