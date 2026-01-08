<?php
declare(strict_types=1);

namespace Laas\Http;

final class Response
{
    public function __construct(
        private string $body = '',
        private int $status = 200,
        private array $headers = []
    ) {
    }

    public static function json(array $data, int $status = 200): self
    {
        $body = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($body === false) {
            $body = '{}';
        }

        return new self($body, $status, [
            'Content-Type' => 'application/json; charset=utf-8',
        ]);
    }

    public static function html(string $body, int $status = 200): self
    {
        return new self($body, $status, [
            'Content-Type' => 'text/html; charset=utf-8',
        ]);
    }

    public function send(): void
    {
        http_response_code($this->status);
        foreach ($this->headers as $name => $value) {
            $replace = strtolower($name) !== 'set-cookie';
            header($name . ': ' . $value, $replace);
        }

        echo $this->body;
    }

    public function getBody(): string
    {
        return $this->body;
    }

    public function getStatus(): int
    {
        return $this->status;
    }

    public function getHeaders(): array
    {
        return $this->headers;
    }

    public function getHeader(string $name): ?string
    {
        $key = strtolower($name);
        foreach ($this->headers as $headerName => $value) {
            if (strtolower($headerName) === $key) {
                return $value;
            }
        }

        return null;
    }

    public function withHeader(string $name, string $value): self
    {
        $headers = $this->headers;
        $headers[$name] = $value;

        return new self($this->body, $this->status, $headers);
    }

    public function withBody(string $body): self
    {
        return new self($body, $this->status, $this->headers);
    }
}
