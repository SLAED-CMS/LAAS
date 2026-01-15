<?php
declare(strict_types=1);

namespace Laas\Support;

use RuntimeException;

final class SafeHttpClient
{
    private UrlPolicy $policy;
    private int $timeoutSeconds;
    private int $connectTimeoutSeconds;
    private int $maxRedirects;
    private int $maxBytes;
    /** @var null|callable */
    private $sender;

    /**
     * @param null|callable $sender
     */
    public function __construct(
        UrlPolicy $policy,
        int $timeoutSeconds = 8,
        int $connectTimeoutSeconds = 3,
        int $maxRedirects = 3,
        int $maxBytes = 2_000_000,
        ?callable $sender = null
    ) {
        $this->policy = $policy;
        $this->timeoutSeconds = max(1, $timeoutSeconds);
        $this->connectTimeoutSeconds = max(1, $connectTimeoutSeconds);
        $this->maxRedirects = max(0, $maxRedirects);
        $this->maxBytes = max(1, $maxBytes);
        $this->sender = $sender;
    }

    public static function fromConfig(array $config, UrlPolicy $policy): self
    {
        $client = is_array($config['client'] ?? null) ? $config['client'] : [];
        return new self(
            $policy,
            (int) ($client['timeout_seconds'] ?? 8),
            (int) ($client['connect_timeout_seconds'] ?? 3),
            (int) ($client['max_redirects'] ?? 3),
            (int) ($client['max_bytes'] ?? 2_000_000)
        );
    }

    /**
     * @param array<int|string, string> $headers
     * @param array<string, mixed> $options
     * @return array{status: int, headers: array<string, string>, body: string}
     */
    public function request(string $method, string $url, array $headers = [], ?string $body = null, array $options = []): array
    {
        $method = strtoupper($method);
        $policy = isset($options['policy']) && $options['policy'] instanceof UrlPolicy
            ? $options['policy']
            : $this->policy;
        $maxRedirects = isset($options['max_redirects']) ? (int) $options['max_redirects'] : $this->maxRedirects;
        $maxRedirects = max(0, $maxRedirects);

        UrlValidator::assertSafeHttpUrl($url, $policy);

        $currentUrl = $url;
        $currentMethod = $method;
        $currentBody = $body;

        for ($redirects = 0; $redirects <= $maxRedirects; $redirects++) {
            $response = $this->send($currentMethod, $currentUrl, $headers, $currentBody, $options);
            $status = (int) ($response['status'] ?? 0);
            $outHeaders = is_array($response['headers'] ?? null) ? $response['headers'] : [];

            if (!$this->isRedirect($status)) {
                return $response;
            }

            $location = $this->headerValue($outHeaders, 'location');
            if ($location === '' || $redirects >= $maxRedirects) {
                return $response;
            }

            $nextUrl = $this->resolveRedirectUrl($currentUrl, $location);
            UrlValidator::assertSafeHttpUrl($nextUrl, $policy);

            if (in_array($status, [301, 302, 303], true)) {
                $currentMethod = 'GET';
                $currentBody = null;
                $headers = $this->stripBodyHeaders($headers);
            }

            $currentUrl = $nextUrl;
        }

        return $this->send($currentMethod, $currentUrl, $headers, $currentBody, $options);
    }

    /**
     * @param array<int|string, string> $headers
     * @param array<string, mixed> $options
     * @return array{status: int, headers: array<string, string>, body: string}
     */
    private function send(string $method, string $url, array $headers, ?string $body, array $options): array
    {
        if ($this->sender !== null) {
            $result = ($this->sender)($method, $url, $headers, $body, $options);
            if (is_array($result)) {
                return [
                    'status' => (int) ($result['status'] ?? 0),
                    'headers' => is_array($result['headers'] ?? null) ? $this->normalizeHeaders($result['headers']) : [],
                    'body' => (string) ($result['body'] ?? ''),
                ];
            }
        }

        return $this->curlSend($method, $url, $headers, $body, $options);
    }

    /**
     * @param array<int|string, string> $headers
     * @param array<string, mixed> $options
     * @return array{status: int, headers: array<string, string>, body: string}
     */
    private function curlSend(string $method, string $url, array $headers, ?string $body, array $options): array
    {
        $timeout = isset($options['timeout']) ? (int) $options['timeout'] : $this->timeoutSeconds;
        $timeout = max(1, $timeout);
        $connectTimeout = isset($options['connect_timeout'])
            ? (int) $options['connect_timeout']
            : min($this->connectTimeoutSeconds, $timeout);
        $connectTimeout = max(1, $connectTimeout);
        $maxBytes = isset($options['max_bytes']) ? (int) $options['max_bytes'] : $this->maxBytes;
        $maxBytes = max(1, $maxBytes);
        $verifyTls = $options['verify_tls'] ?? true;

        $headerLines = $this->normalizeHeaderLines($headers);
        $headerRaw = '';
        $bodyBuffer = '';
        $tooLarge = false;
        $started = microtime(true);

        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('http_request_failed');
        }

        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $connectTimeout);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
        curl_setopt($ch, CURLOPT_MAXREDIRS, 0);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, false);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headerLines);
        curl_setopt($ch, CURLOPT_HEADERFUNCTION, static function ($ch, string $line) use (&$headerRaw): int {
            $headerRaw .= $line;
            return strlen($line);
        });
        curl_setopt($ch, CURLOPT_WRITEFUNCTION, static function ($ch, string $data) use (&$bodyBuffer, $maxBytes, &$tooLarge): int {
            $size = strlen($data);
            if (strlen($bodyBuffer) + $size > $maxBytes) {
                $tooLarge = true;
                return 0;
            }
            $bodyBuffer .= $data;
            return $size;
        });

        if (defined('CURLOPT_PROTOCOLS')) {
            curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS);
        }
        if (defined('CURLOPT_REDIR_PROTOCOLS')) {
            curl_setopt($ch, CURLOPT_REDIR_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS);
        }

        if ($method === 'HEAD') {
            curl_setopt($ch, CURLOPT_NOBODY, true);
        } elseif ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        if (!$verifyTls) {
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        }

        $ok = curl_exec($ch);
        if ($ok === false) {
            curl_close($ch);
            if ($tooLarge) {
                throw new RuntimeException('http_response_too_large');
            }
            throw new RuntimeException('http_request_failed');
        }

        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $durationMs = (microtime(true) - $started) * 1000;
        $context = RequestScope::get('devtools.context');
        if ($context instanceof \Laas\DevTools\DevToolsContext) {
            $context->addExternalCall($method, $url, $status, $durationMs);
        }

        $headersOut = $this->parseHeaders($headerRaw);

        return [
            'status' => $status,
            'headers' => $headersOut,
            'body' => (string) $bodyBuffer,
        ];
    }

    private function isRedirect(int $status): bool
    {
        return in_array($status, [301, 302, 303, 307, 308], true);
    }

    /** @param array<string, string> $headers */
    private function headerValue(array $headers, string $name): string
    {
        $key = strtolower($name);
        return (string) ($headers[$key] ?? '');
    }

    /**
     * @param array<int|string, string> $headers
     * @return array<int, string>
     */
    private function normalizeHeaderLines(array $headers): array
    {
        $out = [];
        foreach ($headers as $key => $value) {
            if (is_int($key)) {
                $out[] = (string) $value;
                continue;
            }
            $name = trim((string) $key);
            if ($name === '') {
                continue;
            }
            $out[] = $name . ': ' . (string) $value;
        }
        return $out;
    }

    /**
     * @param array<int|string, string> $headers
     * @return array<string, string>
     */
    private function normalizeHeaders(array $headers): array
    {
        $out = [];
        foreach ($headers as $key => $value) {
            if (is_int($key)) {
                if (!is_string($value) || !str_contains($value, ':')) {
                    continue;
                }
                [$name, $val] = explode(':', $value, 2);
                $out[strtolower(trim($name))] = trim($val);
                continue;
            }
            $out[strtolower(trim((string) $key))] = trim((string) $value);
        }
        return $out;
    }

    /** @return array<string, string> */
    private function parseHeaders(string $raw): array
    {
        $headers = [];
        $lines = preg_split("/\r?\n/", $raw) ?: [];
        foreach ($lines as $line) {
            if (strpos($line, ':') === false) {
                continue;
            }
            [$name, $value] = explode(':', $line, 2);
            $headers[strtolower(trim($name))] = trim($value);
        }
        return $headers;
    }

    /**
     * @param array<int|string, string> $headers
     * @return array<int|string, string>
     */
    private function stripBodyHeaders(array $headers): array
    {
        $out = [];
        foreach ($headers as $key => $value) {
            $name = is_int($key) ? (string) $value : (string) $key;
            $lower = strtolower($name);
            if (str_starts_with($lower, 'content-') || $lower === 'content-length') {
                continue;
            }
            $out[$key] = $value;
        }
        return $out;
    }

    private function resolveRedirectUrl(string $baseUrl, string $location): string
    {
        $location = trim($location);
        if ($location === '') {
            return $baseUrl;
        }
        if (parse_url($location, PHP_URL_SCHEME) !== null) {
            return $location;
        }

        $base = parse_url($baseUrl);
        if (!is_array($base) || ($base['scheme'] ?? '') === '' || ($base['host'] ?? '') === '') {
            return $location;
        }

        $scheme = (string) $base['scheme'];
        $host = (string) $base['host'];
        $port = isset($base['port']) ? ':' . $base['port'] : '';

        if (str_starts_with($location, '//')) {
            return $scheme . ':' . $location;
        }

        if (str_starts_with($location, '/')) {
            return $scheme . '://' . $host . $port . $location;
        }

        $path = (string) ($base['path'] ?? '/');
        $dir = rtrim(substr($path, 0, (int) strrpos($path, '/') + 1), '/');
        $dir = $dir === '' ? '' : '/' . $dir;
        return $scheme . '://' . $host . $port . $dir . '/' . $location;
    }
}
