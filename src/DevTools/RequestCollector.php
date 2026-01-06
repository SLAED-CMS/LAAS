<?php
declare(strict_types=1);

namespace Laas\DevTools;

use Laas\Http\Request;
use Laas\Http\Response;

final class RequestCollector implements CollectorInterface
{
    private array $maskKeys = [
        'password',
        'pass',
        'pwd',
        'token',
        'csrf',
        '_token',
        'authorization',
        'cookie',
        'set-cookie',
        'session',
        'laasid',
        'jwt',
        'secret',
        'api_key',
    ];
    private array $maskPatterns = [
        'token',
        'secret',
        'key',
        'auth',
        'session',
    ];

    public function collect(Request $request, Response $response, DevToolsContext $context): void
    {
        $isDev = (bool) $context->getFlag('is_dev', false);
        $showSecrets = (bool) $context->getFlag('show_secrets', false);
        $unredacted = $context->hasRole('admin') && ($isDev || $showSecrets);
        $get = $this->mask($request->getQuery(), $unredacted);
        $post = $this->mask($request->getPost(), $unredacted);
        $cookies = $this->mask($_COOKIE ?? [], $unredacted);
        $headersRaw = $this->withResponseHeaders($request->getHeaders(), $response);
        $headers = $this->mask($headersRaw, $unredacted);
        $routeInfo = $this->resolveRouteInfo($request);

        $context->setRequest([
            'method' => $request->getMethod(),
            'path' => $request->getPath(),
            'route' => $routeInfo['route'],
            'controller' => $routeInfo['controller'],
            'action' => $routeInfo['action'],
            'get' => $get,
            'get_raw' => $this->formatVars($request->getQuery(), $unredacted),
            'post' => $post,
            'post_raw' => $this->formatVars($request->getPost(), $unredacted),
            'cookies' => $cookies,
            'headers' => $headers,
        ]);

        $mediaId = $this->toInt($response->getHeader('X-Media-Id'));
        if ($mediaId !== null) {
            $context->setMedia([
                'id' => $mediaId,
                'mime' => (string) ($response->getHeader('X-Media-Mime') ?? ''),
                'size' => $this->toInt($response->getHeader('X-Media-Size')) ?? 0,
                'mode' => (string) ($response->getHeader('X-Media-Mode') ?? ''),
                'disk' => (string) ($response->getHeader('X-Media-Disk') ?? ''),
                'storage' => (string) ($response->getHeader('X-Media-Storage') ?? ''),
                'object_key' => (string) ($response->getHeader('X-Media-Object-Key') ?? ''),
                'read_time_ms' => $this->toFloat($response->getHeader('X-Media-Read-Time')) ?? 0.0,
                'thumb_generated' => ($this->toInt($response->getHeader('X-Media-Thumb-Generated')) ?? 0) > 0,
                'thumb_reason' => (string) ($response->getHeader('X-Media-Thumb-Reason') ?? ''),
                'thumb_algo' => $this->toInt($response->getHeader('X-Media-Thumb-Algo')) ?? 0,
                'access_mode' => (string) ($response->getHeader('X-Media-Access-Mode') ?? ''),
                'signature_valid' => ($this->toInt($response->getHeader('X-Media-Signature-Valid')) ?? 0) > 0,
                'signature_exp' => $this->toInt($response->getHeader('X-Media-Signature-Exp')) ?? 0,
                's3_requests' => $this->toInt($response->getHeader('X-Media-S3-Requests')) ?? 0,
                's3_time_ms' => $this->toFloat($response->getHeader('X-Media-S3-Time')) ?? 0.0,
            ]);
        }
    }

    /** @return array{route: ?string, controller: ?string, action: ?string} */
    private function resolveRouteInfo(Request $request): array
    {
        $route = $request->getAttribute('route.pattern');
        $handler = $request->getAttribute('route.handler');
        $controller = null;
        $action = null;

        if (is_array($handler) && count($handler) >= 2) {
            $controller = is_object($handler[0]) ? get_class($handler[0]) : (string) $handler[0];
            $action = (string) $handler[1];
        }

        return [
            'route' => is_string($route) && $route !== '' ? $route : null,
            'controller' => $controller !== '' ? $controller : null,
            'action' => $action !== '' ? $action : null,
        ];
    }

    private function mask(array $data, bool $unredacted = false): array
    {
        $result = [];
        foreach ($data as $key => $value) {
            $name = strtolower((string) $key);
            if (!$unredacted && $this->isSensitive($name)) {
                $result[] = ['key' => (string) $key, 'value' => '[redacted]'];
                continue;
            }

            if (is_array($value)) {
                if ($unredacted) {
                    $encoded = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                    $result[] = ['key' => (string) $key, 'value' => $encoded !== false ? $encoded : '[array]'];
                } else {
                    $result[] = ['key' => (string) $key, 'value' => '[array]'];
                }
                continue;
            }

            $result[] = ['key' => (string) $key, 'value' => (string) $value];
        }

        return $result;
    }

    private function formatVars(array $data, bool $unredacted): string
    {
        $normalized = $unredacted ? $data : $this->maskRecursive($data);
        $dump = print_r($normalized, true);
        $dump = preg_replace('/\s+/', ' ', trim($dump)) ?? trim($dump);
        return $dump !== '' ? $dump : 'Array ( )';
    }

    private function maskRecursive(array $data): array
    {
        $masked = [];
        foreach ($data as $key => $value) {
            $name = strtolower((string) $key);
            if ($this->isSensitive($name)) {
                $masked[$key] = '[redacted]';
                continue;
            }

            if (is_array($value)) {
                $masked[$key] = $this->maskRecursive($value);
                continue;
            }

            $masked[$key] = $value;
        }

        return $masked;
    }

    private function withResponseHeaders(array $headers, Response $response): array
    {
        $contentType = $headers['content-type'] ?? '';
        if ($contentType === '') {
            $respContentType = $response->getHeader('Content-Type') ?? '';
            if ($respContentType !== '') {
                $headers['content-type'] = $respContentType;
            }
        }

        $contentLength = $headers['content-length'] ?? '';
        if ($contentLength === '') {
            $respContentLength = $response->getHeader('Content-Length') ?? '';
            if ($respContentLength !== '') {
                $headers['content-length'] = $respContentLength;
            } else {
                $bodyLength = strlen($response->getBody());
                if ($bodyLength > 0) {
                    $headers['content-length'] = (string) $bodyLength;
                }
            }
        }

        return $headers;
    }

    private function isSensitive(string $key): bool
    {
        foreach ($this->maskKeys as $maskKey) {
            if (str_contains($key, $maskKey)) {
                return true;
            }
        }

        foreach ($this->maskPatterns as $pattern) {
            if (str_contains($key, $pattern)) {
                return true;
            }
        }

        return false;
    }

    private function toInt(?string $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_numeric($value)) {
            return null;
        }
        return (int) $value;
    }

    private function toFloat(?string $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_numeric($value)) {
            return null;
        }
        return (float) $value;
    }
}
