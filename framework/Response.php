<?php

declare(strict_types=1);

namespace SDL\Framework;

final class Response
{
    public function __construct(
        private readonly string $content = '',
        private readonly int $status = 200,
        private readonly array $headers = ['Content-Type' => 'application/json; charset=utf-8']
    ) {
    }

    public static function json(array $data, int $status = 200, array $headers = []): self
    {
        $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        return new self($json, $status, array_merge([
            'Content-Type' => 'application/json; charset=utf-8',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'no-store'
        ], $headers));
    }

    public static function noContent(): self
    {
        return new self('', 204, []);
    }

    public function withHeader(string $name, string $value): self
    {
        $headers = $this->headers;
        $headers[$name] = $value;
        return new self($this->content, $this->status, $headers);
    }

    public function send(): void
    {
        http_response_code($this->status);
        foreach ($this->headers as $name => $value) {
            header($name . ': ' . $value);
        }
        echo $this->content;
    }

    public function status(): int { return $this->status; }
    public function content(): string { return $this->content; }
}
