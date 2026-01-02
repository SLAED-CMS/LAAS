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
}
