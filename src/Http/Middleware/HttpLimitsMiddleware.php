<?php

declare(strict_types=1);

namespace Laas\Http\Middleware;

use Laas\Http\ErrorResponse;
use Laas\Http\HeadlessMode;
use Laas\Http\Request;
use Laas\Http\Response;
use Laas\View\View;

final class HttpLimitsMiddleware implements MiddlewareInterface
{
    public function __construct(
        private array $config,
        private ?View $view = null
    ) {
    }

    public function process(Request $request, callable $next): Response
    {
        $limits = $this->normalizeConfig($this->config);

        if (!$this->trustedHostAllowed($request, $limits['trusted_hosts'])) {
            return $this->reject($request, 'invalid_request', 400);
        }

        if ($this->headerBytes($request) > $limits['max_header_bytes']) {
            return $this->reject($request, 'http.headers_too_large', 431);
        }

        if ($this->urlLength($request) > $limits['max_url_length']) {
            return $this->reject($request, 'http.uri_too_long', 414);
        }

        $contentLength = $this->contentLength($request);
        if ($contentLength !== null && $contentLength > $limits['max_body_bytes']) {
            return $this->reject($request, 'http.payload_too_large', 413);
        }

        if ((bool) $request->getAttribute('http.body_overflow', false)) {
            return $this->reject($request, 'http.payload_too_large', 413);
        }

        if ($this->postFieldsCount($request->getPost()) > $limits['max_post_fields']) {
            return $this->reject($request, 'http.too_many_fields', 400);
        }

        $files = $this->collectFiles($_FILES);
        if (count($files) > $limits['max_files']) {
            return $this->reject($request, 'http.payload_too_large', 413);
        }
        foreach ($files as $file) {
            $size = (int) ($file['size'] ?? 0);
            if ($size > $limits['max_file_bytes']) {
                return $this->reject($request, 'http.payload_too_large', 413);
            }
        }

        $method = strtoupper($request->getMethod());
        if ($method !== 'GET' && $method !== 'HEAD') {
            if ($this->isJsonContent($request)) {
                $raw = $request->getBody();
                if (trim($raw) !== '') {
                    json_decode($raw, true);
                    if (json_last_error() !== JSON_ERROR_NONE) {
                        return $this->reject($request, 'http.invalid_json', 400);
                    }
                }
            }
        }

        return $next($request);
    }

    private function normalizeConfig(array $config): array
    {
        $defaults = [
            'max_body_bytes' => 2_000_000,
            'max_post_fields' => 200,
            'max_header_bytes' => 32_000,
            'max_url_length' => 2048,
            'max_files' => 10,
            'max_file_bytes' => 10_000_000,
            'trusted_hosts' => [],
        ];

        $merged = array_merge($defaults, $config);
        foreach ($defaults as $key => $value) {
            if (is_int($value)) {
                $merged[$key] = max(0, (int) ($merged[$key] ?? $value));
            }
        }
        if (!is_array($merged['trusted_hosts'])) {
            $merged['trusted_hosts'] = [];
        }

        return $merged;
    }

    private function contentLength(Request $request): ?int
    {
        $raw = $request->getHeader('content-length');
        if ($raw === null || $raw === '') {
            return null;
        }
        if (!ctype_digit($raw)) {
            return null;
        }

        return (int) $raw;
    }

    private function headerBytes(Request $request): int
    {
        $total = 0;
        foreach ($request->getHeaders() as $name => $value) {
            $total += strlen((string) $name) + 2 + strlen((string) $value) + 2;
        }

        return $total;
    }

    private function urlLength(Request $request): int
    {
        $uri = $_SERVER['REQUEST_URI'] ?? '';
        if (is_string($uri) && $uri !== '') {
            return strlen($uri);
        }

        $path = $request->getPath();
        $query = $request->getQuery();
        if ($query === []) {
            return strlen($path);
        }

        $qs = http_build_query($query);
        return strlen($path) + 1 + strlen($qs);
    }

    private function postFieldsCount(array $post): int
    {
        return $this->countRecursive($post);
    }

    private function countRecursive(array $data): int
    {
        $count = 0;
        foreach ($data as $value) {
            if (is_array($value)) {
                $count += $this->countRecursive($value);
                continue;
            }
            $count++;
        }

        return $count;
    }

    private function collectFiles(array $files): array
    {
        $collected = [];
        foreach ($files as $spec) {
            if (!is_array($spec)) {
                continue;
            }
            if (array_key_exists('name', $spec)) {
                $collected = array_merge($collected, $this->flattenFileSpec($spec));
                continue;
            }
            foreach ($spec as $child) {
                if (is_array($child)) {
                    $collected = array_merge($collected, $this->collectFiles([$child]));
                }
            }
        }

        return $collected;
    }

    private function flattenFileSpec(array $spec): array
    {
        $names = $spec['name'] ?? null;
        $sizes = $spec['size'] ?? null;
        $errors = $spec['error'] ?? null;

        if (!is_array($names)) {
            $entry = [
                'name' => $names,
                'size' => $sizes,
                'error' => $errors,
            ];
            return $this->shouldCountFile($entry) ? [$entry] : [];
        }

        $flat = [];
        foreach ($names as $idx => $name) {
            $entry = [
                'name' => $name,
                'size' => is_array($sizes) ? ($sizes[$idx] ?? 0) : 0,
                'error' => is_array($errors) ? ($errors[$idx] ?? null) : null,
            ];
            if (is_array($name)) {
                $flat = array_merge($flat, $this->flattenFileSpec([
                    'name' => $name,
                    'size' => is_array($sizes) ? ($sizes[$idx] ?? []) : [],
                    'error' => is_array($errors) ? ($errors[$idx] ?? []) : [],
                ]));
                continue;
            }

            if ($this->shouldCountFile($entry)) {
                $flat[] = $entry;
            }
        }

        return $flat;
    }

    private function shouldCountFile(array $entry): bool
    {
        $error = $entry['error'] ?? null;
        if ($error === UPLOAD_ERR_NO_FILE) {
            return false;
        }
        $name = $entry['name'] ?? '';
        if ($name === null || $name === '') {
            return false;
        }

        return true;
    }

    private function isJsonContent(Request $request): bool
    {
        $contentType = strtolower((string) ($request->getHeader('content-type') ?? ''));
        return str_contains($contentType, 'application/json');
    }

    private function trustedHostAllowed(Request $request, array $trustedHosts): bool
    {
        if ($trustedHosts === []) {
            return true;
        }

        $host = (string) ($request->getHeader('host') ?? '');
        if ($host === '') {
            return false;
        }

        $host = strtolower(trim($host));
        $host = preg_replace('/:\\d+$/', '', $host) ?? $host;
        if ($host === '') {
            return false;
        }

        foreach ($trustedHosts as $allowed) {
            if (!is_string($allowed) || $allowed === '') {
                continue;
            }
            $allowed = strtolower(trim($allowed));
            if ($allowed === '') {
                continue;
            }
            if ($allowed === $host) {
                return true;
            }
            if (str_starts_with($allowed, '*.')) {
                $suffix = substr($allowed, 1);
                if ($suffix !== '' && str_ends_with($host, $suffix)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function reject(Request $request, string $errorKey, int $status): Response
    {
        return ErrorResponse::respondForRequest($request, $errorKey, [], $status, [
            'route' => HeadlessMode::resolveRoute($request),
        ], 'http.limits');
    }
}
