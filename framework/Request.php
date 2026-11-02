<?php

declare(strict_types=1);

namespace SDL\Framework;

final class Request
{
    private array $routeParams = [];
    private array $attributes = [];

    public function __construct(
        private readonly string $method,
        private readonly string $path,
        private readonly array $query = [],
        private readonly array $headers = [],
        private readonly array $body = [],
        private readonly array $server = []
    ) {
    }

    public static function fromGlobals(): self
    {
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        $path = parse_url($uri, PHP_URL_PATH);
        $path = is_string($path) && $path !== '' ? $path : '/';
        $path = '/' . trim($path, '/');
        if ($path === '//') {
            $path = '/';
        }

        $headers = function_exists('getallheaders') ? getallheaders() : [];
        foreach ($_SERVER as $key => $value) {
            if (str_starts_with($key, 'HTTP_')) {
                $name = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($key, 5)))));
                $headers[$name] ??= $value;
            }
        }

        $raw = file_get_contents('php://input');
        $body = [];
        if (is_string($raw) && trim($raw) !== '') {
            $contentType = strtolower((string) ($headers['Content-Type'] ?? $headers['content-type'] ?? ''));
            if (str_contains($contentType, 'application/json')) {
                try {
                    $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
                } catch (\JsonException $exception) {
                    throw new HttpException(400, 'Malformed JSON request body', 'invalid_json');
                }

                if (!is_array($decoded)) {
                    throw new HttpException(400, 'JSON body must be an object or array', 'invalid_json_shape');
                }
                $body = $decoded;
            } else {
                $body = $_POST;
            }
        } else {
            $body = $_POST;
        }

        return new self($method, $path, $_GET, self::normalizeHeaders($headers), $body, $_SERVER);
    }

    private static function normalizeHeaders(array $headers): array
    {
        $normalized = [];
        foreach ($headers as $name => $value) {
            $normalized[strtolower((string) $name)] = is_array($value) ? implode(', ', $value) : (string) $value;
        }
        return $normalized;
    }

    public function method(): string { return $this->method; }
    public function path(): string { return $this->path; }
    public function query(): array { return $this->query; }
    public function body(): array { return $this->body; }
    public function all(): array { return array_merge($this->query, $this->body); }
    public function header(string $name, ?string $default = null): ?string
    {
        return $this->headers[strtolower($name)] ?? $default;
    }
    public function routeParam(string $name, mixed $default = null): mixed
    {
        return $this->routeParams[$name] ?? $default;
    }
    public function routeParams(): array { return $this->routeParams; }
    public function setRouteParams(array $params): void { $this->routeParams = $params; }
    public function setAttribute(string $name, mixed $value): void { $this->attributes[$name] = $value; }
    public function attribute(string $name, mixed $default = null): mixed { return $this->attributes[$name] ?? $default; }
    public function ip(): string { return (string) ($this->server['REMOTE_ADDR'] ?? 'unknown'); }
}
