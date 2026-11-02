<?php
declare(strict_types=1);
namespace App\Services;
/** Compatibility with legacy SDL encodel(). Base64/reversal is not secure password hashing. */
final class LegacyPasswordEncoder
{
    public function encode(string $value): string
    {
        for ($i = 0; $i < 5; $i++) $value = strrev(base64_encode($value));
        return $value;
    }
}
