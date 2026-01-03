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
        $get = $this->mask($request->getQuery());
        $post = $this->mask($request->getPost());
        $cookies = $this->mask($_COOKIE ?? []);
        $headers = $this->mask($request->getHeaders());

        $context->setRequest([
            'method' => $request->getMethod(),
            'path' => $request->getPath(),
            'get' => $get,
            'post' => $post,
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
                'read_time_ms' => $this->toFloat($response->getHeader('X-Media-Read-Time')) ?? 0.0,
                'thumb_generated' => ($this->toInt($response->getHeader('X-Media-Thumb-Generated')) ?? 0) > 0,
                'thumb_reason' => (string) ($response->getHeader('X-Media-Thumb-Reason') ?? ''),
                'thumb_algo' => $this->toInt($response->getHeader('X-Media-Thumb-Algo')) ?? 0,
                'access_mode' => (string) ($response->getHeader('X-Media-Access-Mode') ?? ''),
                'signature_valid' => ($this->toInt($response->getHeader('X-Media-Signature-Valid')) ?? 0) > 0,
                'signature_exp' => $this->toInt($response->getHeader('X-Media-Signature-Exp')) ?? 0,
            ]);
        }
    }

    private function mask(array $data): array
    {
        $result = [];
        foreach ($data as $key => $value) {
            $name = strtolower((string) $key);
            if ($this->isSensitive($name)) {
                $result[] = ['key' => (string) $key, 'value' => '[redacted]'];
                continue;
            }

            if (is_array($value)) {
                $result[] = ['key' => (string) $key, 'value' => '[array]'];
                continue;
            }

            $result[] = ['key' => (string) $key, 'value' => (string) $value];
        }

        return $result;
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
