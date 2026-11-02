<?php

declare(strict_types=1);

namespace App\Models;

use SDL\Framework\Model;

final class OracleHealthModel extends Model
{
    public function check(): bool
    {
        return $this->fetchOne('SELECT 1 AS HEALTH_CHECK FROM DUAL') !== null;
    }
}
