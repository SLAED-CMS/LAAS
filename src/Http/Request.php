<?php
declare(strict_types=1);

namespace Laas\Http;

final class Request
{
    public function __construct(
        private string $method,
        private string $path,
        private array $query,
        private array $post,
        private array $headers,
        private string $body
    ) {
    }

    public static function fromGlobals(): self
    {
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        $path = parse_url($uri, PHP_URL_PATH) ?? '/';
        $query = $_GET ?? [];
        $post = $_POST ?? [];
        $rawHeaders = function_exists('getallheaders') ? getallheaders() : [];
        $headers = [];
        foreach ($rawHeaders as $name => $value) {
            $headers[strtolower((string) $name)] = (string) $value;
        }
        $body = (string) file_get_contents('php://input');

        return new self($method, $path, $query, $post, $headers, $body);
    }

    public function getMethod(): string
    {
        return $this->method;
    }

    public function getPath(): string
    {
        return $this->path;
    }

    public function getQuery(): array
    {
        return $this->query;
    }

    public function query(string $key): ?string
    {
        $value = $this->query[$key] ?? null;

        if ($value === null) {
            return null;
        }

        return is_string($value) ? $value : null;
    }

    public function getPost(): array
    {
        return $this->post;
    }

    public function post(string $key): ?string
    {
        $value = $this->post[$key] ?? null;

        if ($value === null) {
            return null;
        }

        return is_string($value) ? $value : null;
    }

    public function getHeaders(): array
    {
        return $this->headers;
    }

    public function header(string $name): ?string
    {
        return $this->getHeader($name);
    }

    public function getHeader(string $name): ?string
    {
        $key = strtolower($name);

        return $this->headers[$key] ?? null;
    }

    public function wantsJson(): bool
    {
        $accept = $this->getHeader('accept') ?? '';
        if (stripos($accept, 'application/json') !== false) {
            return true;
        }

        return str_starts_with($this->path, '/api/');
    }

    public function expectsJson(): bool
    {
        return $this->wantsJson();
    }

    public function ip(): string
    {
        return (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
    }

    public function isHtmx(): bool
    {
        return strtolower((string) ($this->getHeader('hx-request') ?? '')) === 'true';
    }

    public function getBody(): string
    {
        return $this->body;
    }
}
