<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Middleware\AuthenticationMiddleware;
use App\Services\TokenService;
use SDL\Framework\Request;
use SDL\Framework\Response;

function check(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$directory = sys_get_temp_dir();
$privatePath = tempnam($directory, 'sdl-jwt-private-');
$publicPath = tempnam($directory, 'sdl-jwt-public-');
if ($privatePath === false || $publicPath === false) throw new RuntimeException('Unable to create temporary key files.');

try {
    if (getenv('OPENSSL_CONF') === false && PHP_OS_FAMILY === 'Windows') {
        $xamppConfig = dirname(PHP_BINARY) . DIRECTORY_SEPARATOR . 'extras' . DIRECTORY_SEPARATOR . 'ssl' . DIRECTORY_SEPARATOR . 'openssl.cnf';
        if (is_file($xamppConfig)) putenv('OPENSSL_CONF=' . $xamppConfig);
    }
    $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
    if ($key !== false && openssl_pkey_export($key, $privatePem)) {
        $details = openssl_pkey_get_details($key);
        if (!is_array($details) || !is_string($details['key'] ?? null)) throw new RuntimeException('Unable to export test public key.');
        file_put_contents($privatePath, $privatePem);
        file_put_contents($publicPath, $details['key']);
    } else {
        // Some XAMPP CLI builds cannot initialize OpenSSL's RNG configuration.
        $openssl = PHP_OS_FAMILY === 'Windows' ? 'C:\\xampp\\apache\\bin\\openssl.exe' : 'openssl';
        if (!is_file($openssl) && $openssl !== 'openssl') throw new RuntimeException('Unable to generate test key pair.');
        runCommand($openssl . ' genrsa -out ' . escapeshellarg($privatePath) . ' 2048');
        runCommand($openssl . ' rsa -in ' . escapeshellarg($privatePath) . ' -pubout -out ' . escapeshellarg($publicPath));
        $privatePem = file_get_contents($privatePath);
        if (!is_string($privatePem) || $privatePem === '' || !is_file($publicPath)) throw new RuntimeException('Unable to generate test key pair.');
    }

    putenv('JWT_PRIVATE_KEY_PATH=' . $privatePath);
    putenv('JWT_PUBLIC_KEY_PATH=' . $publicPath);
    putenv('JWT_ISSUER=sdl-psr-test');
    putenv('JWT_AUDIENCE=sdl-psr-mobile-test');
    putenv('JWT_CLOCK_SKEW_SECONDS=0');

    $service = new TokenService();
    $issued = $service->issueAccessToken(123, 'TEST_LOGIN');
    check($issued['expires_in'] === 1800, 'JWT lifetime must be exactly 1800 seconds.');
    $claims = $service->verifyAccessToken($issued['access_token'])['claims'];
    check($claims['sub'] === '123', 'JWT subject is incorrect.');
    check((int) $claims['exp'] - (int) $claims['iat'] === 1800, 'JWT exp must be 30 minutes after iat.');

    $middleware = new AuthenticationMiddleware();
    $protected = static function (Request $request): Response {
        return Response::json(['employee_id' => $request->attribute('authenticated_employee_id')]);
    };
    $validResponse = $middleware->process(new Request('GET', '/protected', [], ['authorization' => 'Bearer ' . $issued['access_token']]), $protected);
    check($validResponse->status() === 200 && str_contains($validResponse->content(), '123'), 'Valid JWT was rejected.');
    check($middleware->process(new Request('GET', '/protected'), $protected)->status() === 401, 'Missing Authorization was accepted.');
    check($middleware->process(new Request('GET', '/protected', [], ['authorization' => 'Bearer invalid']), $protected)->status() === 401, 'Invalid JWT was accepted.');

    $parts = explode('.', $issued['access_token']);
    $modifiedPayload = $parts[0] . '.' . base64UrlEncodeForTest(json_encode(['sub' => '999'])) . '.' . $parts[2];
    check($middleware->process(new Request('GET', '/protected', [], ['authorization' => 'Bearer ' . $modifiedPayload]), $protected)->status() === 401, 'Modified JWT payload was accepted.');

    $expired = $service->issueAccessToken(123, 'TEST_LOGIN', new DateTimeImmutable('-1801 seconds'))['access_token'];
    check($middleware->process(new Request('GET', '/protected', [], ['authorization' => 'Bearer ' . $expired]), $protected)->status() === 401, 'Expired JWT was accepted.');

    $wrongIssuer = signedTestToken($privatePem, ['iss' => 'wrong', 'aud' => 'sdl-psr-mobile-test', 'sub' => '123', 'iat' => time(), 'nbf' => time(), 'exp' => time() + 1800, 'jti' => 'wrong-issuer']);
    check($middleware->process(new Request('GET', '/protected', [], ['authorization' => 'Bearer ' . $wrongIssuer]), $protected)->status() === 401, 'Wrong issuer was accepted.');
    $wrongAudience = signedTestToken($privatePem, ['iss' => 'sdl-psr-test', 'aud' => 'wrong', 'sub' => '123', 'iat' => time(), 'nbf' => time(), 'exp' => time() + 1800, 'jti' => 'wrong-audience']);
    check($middleware->process(new Request('GET', '/protected', [], ['authorization' => 'Bearer ' . $wrongAudience]), $protected)->status() === 401, 'Wrong audience was accepted.');
    $unsupportedHeader = base64UrlEncodeForTest(json_encode(['typ' => 'JWT', 'alg' => 'HS256']));
    $unsupportedAlgorithm = $unsupportedHeader . '.' . $parts[1] . '.' . $parts[2];
    check($middleware->process(new Request('GET', '/protected', [], ['authorization' => 'Bearer ' . $unsupportedAlgorithm]), $protected)->status() === 401, 'Unsupported JWT algorithm was accepted.');

    fwrite(STDOUT, "JWT service and local middleware smoke checks passed. No token values were printed.\n");
} finally {
    @unlink($privatePath);
    @unlink($publicPath);
}

function base64UrlEncodeForTest(string $value): string
{
    return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
}

function signedTestToken(string $privatePem, array $claims): string
{
    $header = base64UrlEncodeForTest(json_encode(['typ' => 'JWT', 'alg' => 'RS256']));
    $payload = base64UrlEncodeForTest(json_encode($claims));
    $input = $header . '.' . $payload;
    openssl_sign($input, $signature, $privatePem, OPENSSL_ALGO_SHA256);
    return $input . '.' . base64UrlEncodeForTest($signature);
}

function runCommand(string $command): void
{
    $result = 1;
    system($command, $result);
    if ($result !== 0) throw new RuntimeException('Test key command failed.');
}
