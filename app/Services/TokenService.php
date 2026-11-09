<?php

declare(strict_types=1);

namespace App\Services;

use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;

/** Stateless RS256 JWT issuance and verification for the initial release. */
final class TokenService
{
    public const ACCESS_TOKEN_TTL_SECONDS = 1800;
    public const ALGORITHM = 'RS256';

    public function issueAccessToken(int $employeeId, string $loginId, ?DateTimeImmutable $now = null): array
    {
        if ($employeeId <= 0) throw new RuntimeException('The employee identifier is invalid.');
        $issuedAt = $now ?? $this->utcNow();
        $expiresAt = $issuedAt->modify('+' . self::ACCESS_TOKEN_TTL_SECONDS . ' seconds');
        $claims = [
            'iss' => $this->issuer(), 'aud' => $this->audience(), 'sub' => (string) $employeeId,
            'iat' => $issuedAt->getTimestamp(), 'nbf' => $issuedAt->getTimestamp(),
            'exp' => $expiresAt->getTimestamp(), 'jti' => bin2hex(random_bytes(16)),
            // Existing audit fields such as tour-plan CHG_BY require the login ID.
            'login_id' => $loginId,
        ];
        $header = ['typ' => 'JWT', 'alg' => self::ALGORITHM];
        $encodedHeader = $this->base64UrlEncode($this->jsonEncode($header));
        $encodedClaims = $this->base64UrlEncode($this->jsonEncode($claims));
        $signingInput = $encodedHeader . '.' . $encodedClaims;
        $signature = '';
        if (!openssl_sign($signingInput, $signature, $this->privateKey(), OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('Unable to sign the access token.');
        }
        return [
            'access_token' => $signingInput . '.' . $this->base64UrlEncode($signature),
            'expires_in' => self::ACCESS_TOKEN_TTL_SECONDS,
            'expires_at' => $expiresAt->format(DATE_ATOM),
            'access_expires_at' => $expiresAt->format(DATE_ATOM),
        ];
    }

    /** @return array{claims:array<string,mixed>,header:array<string,mixed>} */
    public function verifyAccessToken(string $token, ?DateTimeImmutable $now = null): array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3 || in_array('', $parts, true)) throw new RuntimeException('Malformed JWT.');
        $header = $this->decodeJsonObject($parts[0]);
        $claims = $this->decodeJsonObject($parts[1]);
        if (($header['alg'] ?? null) !== self::ALGORITHM || ($header['typ'] ?? null) !== 'JWT') {
            throw new RuntimeException('Unsupported JWT algorithm or type.');
        }
        $signature = $this->base64UrlDecode($parts[2]);
        if (openssl_verify($parts[0] . '.' . $parts[1], $signature, $this->publicKey(), OPENSSL_ALGO_SHA256) !== 1) {
            throw new RuntimeException('Invalid JWT signature.');
        }
        foreach (['iss', 'aud', 'sub', 'iat', 'nbf', 'exp', 'jti'] as $claim) {
            if (!array_key_exists($claim, $claims)) throw new RuntimeException('Required JWT claim is missing.');
        }
        if (!hash_equals($this->issuer(), (string) $claims['iss']) || !$this->audienceMatches($claims['aud'] ?? null)) {
            throw new RuntimeException('JWT issuer or audience is invalid.');
        }
        if (!is_numeric($claims['sub']) || (int) $claims['sub'] <= 0 || (string) (int) $claims['sub'] !== (string) $claims['sub']) {
            throw new RuntimeException('JWT subject is invalid.');
        }
        foreach (['iat', 'nbf', 'exp'] as $claim) {
            if (!is_int($claims[$claim]) && !(is_string($claims[$claim]) && ctype_digit($claims[$claim]))) {
                throw new RuntimeException('JWT time claim is invalid.');
            }
        }
        $timestamp = ($now ?? $this->utcNow())->getTimestamp();
        $skew = $this->clockSkew();
        if ((int) $claims['exp'] <= $timestamp - $skew || (int) $claims['nbf'] > $timestamp + $skew || (int) $claims['iat'] > $timestamp + $skew) {
            throw new RuntimeException('JWT is expired or not yet valid.');
        }
        return ['claims' => $claims, 'header' => $header];
    }

    public function accessTokenExpiry(?DateTimeImmutable $now = null): DateTimeImmutable
    {
        return ($now ?? $this->utcNow())->modify('+' . self::ACCESS_TOKEN_TTL_SECONDS . ' seconds');
    }

    private function issuer(): string { return $this->requiredConfig('JWT_ISSUER'); }
    private function audience(): string { return $this->requiredConfig('JWT_AUDIENCE'); }

    private function privateKey(): mixed
    {
        $contents = @file_get_contents($this->requiredConfig('JWT_PRIVATE_KEY_PATH'));
        if (!is_string($contents) || $contents === '') throw new RuntimeException('JWT private key is unavailable.');
        $key = openssl_pkey_get_private($contents);
        if ($key === false) throw new RuntimeException('JWT private key is invalid.');
        return $key;
    }

    private function publicKey(): mixed
    {
        $contents = @file_get_contents($this->requiredConfig('JWT_PUBLIC_KEY_PATH'));
        if (!is_string($contents) || $contents === '') throw new RuntimeException('JWT public key is unavailable.');
        $key = openssl_pkey_get_public($contents);
        if ($key === false) throw new RuntimeException('JWT public key is invalid.');
        return $key;
    }

    private function requiredConfig(string $name): string
    {
        $value = trim((string) getenv($name));
        if ($value === '') throw new RuntimeException($name . ' is not configured.');
        return $value;
    }

    private function clockSkew(): int
    {
        $value = getenv('JWT_CLOCK_SKEW_SECONDS');
        if ($value === false || $value === '') return 30;
        $skew = filter_var($value, FILTER_VALIDATE_INT);
        if ($skew === false || $skew < 0 || $skew > 300) throw new RuntimeException('JWT clock skew configuration is invalid.');
        return $skew;
    }

    private function audienceMatches(mixed $audience): bool
    {
        $configured = $this->audience();
        return is_string($audience) ? hash_equals($configured, $audience) : is_array($audience) && in_array($configured, $audience, true);
    }

    private function decodeJsonObject(string $value): array
    {
        $decoded = json_decode($this->base64UrlDecode($value), true);
        if (!is_array($decoded)) throw new RuntimeException('Invalid JWT JSON.');
        return $decoded;
    }

    private function jsonEncode(array $value): string
    {
        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    private function utcNow(): DateTimeImmutable { return new DateTimeImmutable('now', new DateTimeZone('UTC')); }
    private function base64UrlEncode(string $bytes): string { return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '='); }

    private function base64UrlDecode(string $value): string
    {
        if ($value === '' || preg_match('/^[A-Za-z0-9_-]+$/', $value) !== 1) throw new RuntimeException('Invalid JWT encoding.');
        $padding = strlen($value) % 4;
        if ($padding === 1) throw new RuntimeException('Invalid JWT encoding.');
        $decoded = base64_decode(strtr($value . ($padding ? str_repeat('=', 4 - $padding) : ''), '-_', '+/'), true);
        if ($decoded === false) throw new RuntimeException('Invalid JWT encoding.');
        return $decoded;
    }
}
