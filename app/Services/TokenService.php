<?php

declare(strict_types=1);

namespace App\Services;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/**
 * Cryptographic token primitives for Phase 4.
 * This class does not persist tokens or authenticate requests by itself.
 */
final class TokenService
{
    public const ACCESS_TOKEN_TTL_SECONDS = 900;       // 15 minutes
    public const REFRESH_TOKEN_TTL_SECONDS = 2592000;  // 30 days

    /** Generate a high-entropy opaque access token (32 random bytes, base64url). */
    public function generateAccessToken(): string
    {
        return $this->base64UrlEncode(random_bytes(32));
    }

    /** Generate a high-entropy opaque refresh token (48 random bytes, base64url). */
    public function generateRefreshToken(): string
    {
        return $this->base64UrlEncode(random_bytes(48));
    }

    /** Generate a 128-bit hexadecimal identifier for a token family/session. */
    public function generateFamilyId(): string
    {
        return bin2hex(random_bytes(16));
    }

    /** Store this hash, never the raw token. SHA-256 output is 64 lowercase hex chars. */
    public function hashToken(string $token): string
    {
        if ($token === '') {
            throw new InvalidArgumentException('Token cannot be empty.');
        }

        return hash('sha256', $token);
    }

    public function accessTokenExpiry(?DateTimeImmutable $now = null): DateTimeImmutable
    {
        return ($now ?? $this->utcNow())->modify('+' . self::ACCESS_TOKEN_TTL_SECONDS . ' seconds');
    }

    public function refreshTokenExpiry(?DateTimeImmutable $now = null): DateTimeImmutable
    {
        return ($now ?? $this->utcNow())->modify('+' . self::REFRESH_TOKEN_TTL_SECONDS . ' seconds');
    }

    /** Format timestamps consistently for Oracle TIMESTAMP WITH TIME ZONE binds. */
    public function formatOracleTimestamp(DateTimeImmutable $dateTime): string
    {
        return $dateTime->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.uP');
    }

    private function utcNow(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    private function base64UrlEncode(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
