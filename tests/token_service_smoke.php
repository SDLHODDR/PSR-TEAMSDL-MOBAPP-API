<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Services\TokenService;

$tokens = new TokenService();
$access = $tokens->generateAccessToken();
$refresh = $tokens->generateRefreshToken();
$family = $tokens->generateFamilyId();

assert($access !== $refresh);
assert(strlen($tokens->hashToken($access)) === 64);
assert(strlen($tokens->hashToken($refresh)) === 64);
assert(strlen($family) === 32);
assert($tokens->accessTokenExpiry() < $tokens->refreshTokenExpiry());
assert($tokens->hashToken($access) !== $access);

fwrite(STDOUT, "TokenService smoke checks passed. No token values were printed.\n");
