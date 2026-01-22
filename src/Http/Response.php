<?php

declare(strict_types=1);

namespace Laas\Http;

use Laas\Support\RequestScope;

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
        $request = RequestScope::getRequest();
        if ($request !== null && HeadlessMode::shouldBlockHtml($request)) {
            $payload = HeadlessMode::buildNotAcceptablePayload($request);
            return self::jsonResponse($payload, 406);
        }

        return self::jsonResponse(self::ensureMeta($data), $status);
    }

    public static function html(string $body, int $status = 200): self
    {
        $request = RequestScope::getRequest();
        if ($request !== null && HeadlessMode::isEnabled()) {
            if (HeadlessMode::shouldBlockHtml($request)) {
                $payload = HeadlessMode::buildNotAcceptablePayload($request);
                return self::jsonResponse($payload, 406);
            }
            if (HeadlessMode::shouldDefaultJson($request)) {
                $meta = ResponseMeta::enrich([
                    'format' => 'json',
                    'route' => HeadlessMode::resolveRoute($request),
                ]);
                return self::jsonResponse([
                    'data' => [],
                    'meta' => $meta,
                ], 200);
            }
        }

        return new self($body, $status, [
            'Content-Type' => 'text/html; charset=utf-8',
        ]);
    }

    public function send(): void
    {
        $request = RequestScope::getRequest();
        if ($request !== null && HeadlessMode::shouldBlockHtml($request)) {
            $payload = HeadlessMode::buildNotAcceptablePayload($request);
            $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if ($body === false) {
                $body = '{}';
            }
            http_response_code(406);
            header('Content-Type: application/json; charset=utf-8', true);
            echo $body;
            return;
        }
        $location = $this->getHeader('Location');
        if ($request !== null && $request->isHeadless() && $request->wantsJson() && $location !== null) {
            if ($this->status >= 300 && $this->status < 400) {
                $body = json_encode(['redirect_to' => $location], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                if ($body === false) {
                    $body = '{}';
                }
                http_response_code(200);
                header('Content-Type: application/json; charset=utf-8', true);
                echo $body;
                return;
            }
        }

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

    public function withToastSuccess(string $code, string $message, ?int $ttlMs = null, ?string $title = null, ?string $dedupeKey = null): self
    {
        if ($ttlMs === null) {
            $ttlMs = 4000;
        }

        return $this->withToastPayload('success', $message, $title, $code, $ttlMs, $dedupeKey);
    }

    public function withToastInfo(string $code, string $message, ?int $ttlMs = null, ?string $title = null, ?string $dedupeKey = null): self
    {
        return $this->withToastPayload('info', $message, $title, $code, $ttlMs, $dedupeKey);
    }

    public function withToastWarning(string $code, string $message, ?int $ttlMs = null, ?string $title = null, ?string $dedupeKey = null): self
    {
        return $this->withToastPayload('warning', $message, $title, $code, $ttlMs, $dedupeKey);
    }

    public function withToastDanger(string $code, string $message, ?int $ttlMs = null, ?string $title = null, ?string $dedupeKey = null): self
    {
        return $this->withToastPayload('danger', $message, $title, $code, $ttlMs, $dedupeKey);
    }

    /**
     */
    private function withToastPayload(
        string $type,
        string $message,
        ?string $title,
        ?string $code,
        ?int $ttlMs,
        ?string $dedupeKey
    ): self {
        $toast = UiToast::payload($type, $message, $title, $code, $ttlMs, $dedupeKey);
        UiEventRegistry::pushEvent($toast);
        return HtmxTrigger::addToast($this, $toast);
    }

    public function withBody(string $body): self
    {
        return new self($body, $this->status, $this->headers);
    }

    public function replace(self $other): void
    {
        $this->body = $other->body;
        $this->status = $other->status;
        $this->headers = $other->headers;
    }

    private static function jsonResponse(array $data, int $status): self
    {
        $body = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($body === false) {
            $body = '{}';
        }

        return new self($body, $status, [
            'Content-Type' => 'application/json; charset=utf-8',
        ]);
    }

    private static function ensureMeta(array $data): array
    {
        if (array_key_exists('meta', $data)) {
            if (is_array($data['meta'])) {
                if (!array_key_exists('request_id', $data['meta']) || !array_key_exists('ts', $data['meta'])) {
                    $data['meta'] = ResponseMeta::enrich($data['meta']);
                }
            }

            return $data;
        }

        $data['meta'] = ResponseMeta::enrich([]);

        return $data;
    }
}
