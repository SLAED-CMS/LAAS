<?php
declare(strict_types=1);

namespace Laas\Http;

use Laas\Session\NativeSession;
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
        foreach ($_SERVER as $name => $value) {
            if (!str_starts_with($name, 'HTTP_')) {
                continue;
            }
            $header = strtolower(str_replace('_', '-', substr($name, 5)));
            if (!isset($headers[$header]) && is_string($value)) {
                $headers[$header] = $value;
            }
        }
        $contentType = $_SERVER['CONTENT_TYPE'] ?? ($_SERVER['HTTP_CONTENT_TYPE'] ?? null);
        if (!isset($headers['content-type']) && is_string($contentType) && $contentType !== '') {
            $headers['content-type'] = $contentType;
        }
        $contentLength = $_SERVER['CONTENT_LENGTH'] ?? ($_SERVER['HTTP_CONTENT_LENGTH'] ?? null);
        if (!isset($headers['content-length']) && is_string($contentLength) && $contentLength !== '') {
            $headers['content-length'] = $contentLength;
        }
        if (!isset($headers['host']) && isset($_SERVER['HTTP_HOST']) && is_string($_SERVER['HTTP_HOST'])) {
            $headers['host'] = (string) $_SERVER['HTTP_HOST'];
        }

        $maxBodyBytes = self::envInt('HTTP_MAX_BODY_BYTES', 2_000_000);
        $body = '';
        $bodyOverflow = false;
        if ($maxBodyBytes <= 0) {
            $body = (string) file_get_contents('php://input');
        } else {
            $limit = $maxBodyBytes + 1;
            $stream = fopen('php://input', 'rb');
            if (is_resource($stream)) {
                while (!feof($stream)) {
                    $chunk = fread($stream, 8192);
                    if ($chunk === false) {
                        break;
                    }
                    $body .= $chunk;
                    if (strlen($body) > $limit) {
                        $bodyOverflow = true;
                        $body = substr($body, 0, $limit);
                        break;
                    }
                }
                fclose($stream);
            }
        }

        $request = new self($method, $path, $query, $post, $headers, $body);
        if ($bodyOverflow) {
            $request->setAttribute('http.body_overflow', true);
        }

        return $request;
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

    private static function envInt(string $key, int $default): int
    {
        $value = $_ENV[$key] ?? null;
        if ($value === null || $value === '') {
            $value = getenv($key) ?: null;
        }
        if ($value === null || $value === '') {
            return $default;
        }
        if (!is_numeric($value)) {
            return $default;
        }

        return (int) $value;
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
        $resolver = new FormatResolver();
        return $resolver->resolve($this) === 'json';
    }

    public function acceptsJson(): bool
    {
        $accept = $this->getHeader('accept') ?? '';
        return stripos($accept, 'application/json') !== false;
    }

    public function expectsJson(): bool
    {
        return $this->wantsJson();
    }

    public function ip(): string
    {
        return TrustProxy::resolveClientIp($_SERVER, $this->headers);
    }

    public function isHttps(): bool
    {
        return TrustProxy::resolveHttps($_SERVER, $this->headers);
    }

    public function isHtmx(): bool
    {
        return strtolower((string) ($this->getHeader('hx-request') ?? '')) === 'true';
    }

    public function isHeadless(): bool
    {
        return HeadlessMode::isEnabled();
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
        return $this->session ?? new NativeSession();
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
