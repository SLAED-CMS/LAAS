<?php
declare(strict_types=1);

namespace Laas\Http;

use Laas\Session\PhpSession;
use Laas\Session\SessionInterface;

final class Request
{
    private ?SessionInterface $session = null;
    private array $attributes = [];

    public function __construct(
        private string $method,
        private string $path,
        private array $query,
        private array $post,
        private array $headers,
        private string $body,
        ?SessionInterface $session = null
    ) {
        $this->session = $session;
    }

    public static function fromGlobals(): self
    {
        $methodRaw = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $method = strtoupper(trim((string) $methodRaw));
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        $path = parse_url($uri, PHP_URL_PATH) ?? '/';
        $query = $_GET ?? [];
        if ($query === [] && is_string($uri)) {
            $queryString = parse_url($uri, PHP_URL_QUERY);
            if (is_string($queryString) && $queryString !== '') {
                parse_str($queryString, $parsed);
                if (is_array($parsed)) {
                    $query = $parsed;
                }
            }
        }
        $post = $_POST ?? [];
        $rawHeaders = function_exists('getallheaders') ? getallheaders() : [];
        $headers = [];
        foreach ($rawHeaders as $name => $value) {
            $headers[strtolower((string) $name)] = (string) $value;
        }
        $contentType = $_SERVER['CONTENT_TYPE'] ?? ($_SERVER['HTTP_CONTENT_TYPE'] ?? null);
        if (!isset($headers['content-type']) && is_string($contentType) && $contentType !== '') {
            $headers['content-type'] = $contentType;
        }
        $contentLength = $_SERVER['CONTENT_LENGTH'] ?? ($_SERVER['HTTP_CONTENT_LENGTH'] ?? null);
        if (!isset($headers['content-length']) && is_string($contentLength) && $contentLength !== '') {
            $headers['content-length'] = $contentLength;
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
        $format = $this->query['format'] ?? null;
        if (is_string($format) && strtolower($format) === 'json') {
            return true;
        }

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

    public function setSession(SessionInterface $session): void
    {
        $this->session = $session;
    }

    public function session(): SessionInterface
    {
        return $this->session ?? new PhpSession();
    }

    public function setAttribute(string $key, mixed $value): void
    {
        $this->attributes[$key] = $value;
    }

    public function getAttribute(string $key, mixed $default = null): mixed
    {
        return $this->attributes[$key] ?? $default;
    }
}
