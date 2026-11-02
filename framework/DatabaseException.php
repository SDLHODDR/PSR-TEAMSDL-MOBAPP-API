<?php

declare(strict_types=1);

namespace SDL\Framework;

use RuntimeException;

final class DatabaseException extends RuntimeException
{
    public function __construct(string $safeMessage = 'A database operation failed')
    {
        parent::__construct($safeMessage);
    }
}
