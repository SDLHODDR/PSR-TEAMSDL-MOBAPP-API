<?php

declare(strict_types=1);

namespace App\Models;

use RuntimeException;

final class RouteCooldownException extends RuntimeException
{
    public function __construct(public readonly int $retryAfterSeconds)
    {
        parent::__construct('A consent OTP was recently requested.');
    }
}
