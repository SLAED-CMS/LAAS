<?php
declare(strict_types=1);

use Laas\Support\SafeHttpClient;
use Laas\Support\UrlPolicy;

/**
 * Smoke-test asset HTTP responses for vendor assets.
 *
 * Usage:
 *   php tools/cli.php assets:http:smoke [--base=https://example.test] [--fixture=/path/to/fixture.php]
 */

const ASSETS_HTTP_SMOKE_TIMEOUT = 2;
const ASSETS_HTTP_SMOKE_FONT_MIN_BYTES = 1024;

if (!class_exists(SafeHttpClient::class) && is_file(__DIR__ . '/../vendor/autoload.php')) {
    require_once __DIR__ . '/../vendor/autoload.php';
}
if (!function_exists('assets_verify_extract_css_urls')) {
    require_once __DIR__ . '/assets-verify.php';
}

/**
 * @param array<int, string> $args
 */
function assets_http_smoke_run(string $rootPath, array $args = []): int
{
    $root = assets_http_smoke_root_path($rootPath);
    $assetsConfig = assets_http_smoke_load_config($root . '/config/assets.php');
    if ($assetsConfig === null) {
        echo "assets.http.smoke.error config/assets.php: missing or invalid\n";
        return 1;
    }
    $appConfig = assets_http_smoke_load_config($root . '/config/app.php') ?? [];

    $base = (string) (assets_http_smoke_get_option($args, 'base') ?? '');
    if ($base === '') {
        $base = (string) ($appConfig['url'] ?? $appConfig['base_url'] ?? '');
    }
    if ($base === '') {
        $base = (string) ($_ENV['APP_URL'] ?? $_ENV['APP_BASE_URL'] ?? '');
    }
    if ($base === '') {
        $base = 'http://localhost';
    }
    $base = rtrim($base, '/');

    $parts = parse_url($base);
    $scheme = is_array($parts) ? (string) ($parts['scheme'] ?? '') : '';
    $host = is_array($parts) ? (string) ($parts['host'] ?? '') : '';
    if ($scheme === '' || $host === '') {
        echo "assets.http.smoke.error base: invalid base URL\n";
        return 1;
    }

    $fixturePath = (string) (assets_http_smoke_get_option($args, 'fixture') ?? ($_ENV['ASSETS_HTTP_SMOKE_FIXTURE'] ?? ''));
    $fixture = $fixturePath !== '' ? assets_http_smoke_load_fixture($fixturePath) : null;
    if ($fixturePath !== '' && $fixture === null) {
        echo "assets.http.smoke.error fixture: unable to load fixture\n";
        return 1;
    }
    $resolver = null;
    if ($fixture !== null) {
        $resolver = static function (string $lookupHost): array {
            return ['127.0.0.1'];
        };
    }
    $policy = new UrlPolicy(['http', 'https'], [$host], true, true, false, [], $resolver);

    $client = new SafeHttpClient(
        $policy,
        ASSETS_HTTP_SMOKE_TIMEOUT,
        ASSETS_HTTP_SMOKE_TIMEOUT,
        0,
        2_000_000,
        $fixture !== null ? assets_http_smoke_fixture_sender($fixture) : null
    );

    $issues = [];
    $requests = assets_http_smoke_targets($root, $assetsConfig, $base, $issues);
    foreach ($requests as $request) {
        $url = $request['url'];
        $path = $request['path'];
        $kind = $request['kind'];
        try {
            $response = $client->request('GET', $url, [], null, [
                'timeout' => ASSETS_HTTP_SMOKE_TIMEOUT,
                'connect_timeout' => ASSETS_HTTP_SMOKE_TIMEOUT,
                'max_redirects' => 0,
            ]);
        } catch (RuntimeException $e) {
            $issues[] = [
                'path' => $path,
                'message' => 'HTTP request failed for ' . $url . ': ' . $e->getMessage()
                    . ' (enable POLICY_HTTP_SMOKE=1 only when server is up)',
            ];
            continue;
        }

        $status = (int) ($response['status'] ?? 0);
        if ($status !== 200) {
            $issues[] = [
                'path' => $path,
                'message' => 'HTTP status ' . $status,
            ];
            continue;
        }

        $headers = is_array($response['headers'] ?? null) ? $response['headers'] : [];
        $contentType = strtolower((string) ($headers['content-type'] ?? ''));
        if (!assets_http_smoke_content_type_ok($contentType, $kind)) {
            $issues[] = [
                'path' => $path,
                'message' => 'Unexpected Content-Type ' . ($contentType !== '' ? $contentType : '(missing)'),
            ];
        }

        $body = (string) ($response['body'] ?? '');
        foreach (assets_http_smoke_body_issues($body, $kind) as $message) {
            $issues[] = [
                'path' => $path,
                'message' => $message,
            ];
        }
    }

    if ($issues === []) {
        echo "assets.http.smoke.ok\n";
        return 0;
    }

    foreach ($issues as $issue) {
        $path = (string) ($issue['path'] ?? '');
        $message = (string) ($issue['message'] ?? '');
        echo 'assets.http.smoke.error ' . $path . ': ' . $message . "\n";
    }

    return 1;
}

function assets_http_smoke_root_path(string $rootPath): string
{
    $rootPath = rtrim($rootPath, '/\\');
    if ($rootPath === '') {
        $rootPath = dirname(__DIR__);
    }
    $real = realpath($rootPath);
    return $real !== false ? $real : $rootPath;
}

/**
 * @return null|array<string, mixed>
 */
function assets_http_smoke_load_config(string $path): ?array
{
    if (!is_file($path)) {
        return null;
    }
    $config = require $path;
    return is_array($config) ? $config : null;
}

/**
 * @param array<int, string> $args
 */
function assets_http_smoke_get_option(array $args, string $name): ?string
{
    $prefix = '--' . $name . '=';
    foreach ($args as $index => $arg) {
        if (str_starts_with($arg, $prefix)) {
            return substr($arg, strlen($prefix));
        }
        if ($arg === '--' . $name) {
            return $args[$index + 1] ?? null;
        }
    }
    return null;
}

/**
 * @param array<string, mixed> $config
 * @param array<int, array{path: string, message: string}> $issues
 * @return array<int, array{url: string, path: string, kind: string}>
 */
function assets_http_smoke_targets(string $root, array $config, string $base, array &$issues): array
{
    $map = [
        'htmx_js' => ['label' => 'htmx.min.js', 'kind' => 'htmx_js'],
        'bootstrap_js' => ['label' => 'bootstrap.bundle.min.js', 'kind' => 'bootstrap_js'],
        'bootstrap_css' => ['label' => 'bootstrap.min.css', 'kind' => 'bootstrap_css'],
        'bootstrap_icons_css' => ['label' => 'bootstrap-icons.min.css', 'kind' => 'icons_css'],
    ];

    $targets = [];
    foreach ($map as $key => $meta) {
        $assetPath = $config[$key] ?? '';
        if (!is_string($assetPath) || trim($assetPath) === '') {
            $issues[] = [
                'path' => 'config/assets.php',
                'message' => 'Missing asset path for ' . $meta['label'],
            ];
            continue;
        }
        $urlPath = assets_http_smoke_url_path($assetPath);
        if ($urlPath === '') {
            $issues[] = [
                'path' => $assetPath,
                'message' => 'Invalid asset URL path',
            ];
            continue;
        }
        $targets[] = [
            'url' => $base . $urlPath,
            'path' => $urlPath,
            'kind' => $meta['kind'],
        ];
    }

    $iconsCss = $config['bootstrap_icons_css'] ?? '';
    if (is_string($iconsCss) && trim($iconsCss) !== '') {
        $iconsUrlPath = assets_http_smoke_url_path($iconsCss);
        $iconsCssPath = assets_verify_local_path($root, $iconsCss);
        $fontUrlPath = assets_http_smoke_first_woff2_url($iconsCssPath, $iconsUrlPath, $issues);
        if ($fontUrlPath !== '') {
            $targets[] = [
                'url' => $base . $fontUrlPath,
                'path' => $fontUrlPath,
                'kind' => 'font_woff2',
            ];
        }
    }

    return $targets;
}

function assets_http_smoke_url_path(string $assetPath): string
{
    $path = assets_verify_extract_path($assetPath);
    if ($path === '') {
        return '';
    }
    if (!str_starts_with($path, '/')) {
        $path = '/' . ltrim($path, '/');
    }
    return $path;
}

/**
 * @param array<int, array{path: string, message: string}> $issues
 */
function assets_http_smoke_first_woff2_url(string $cssPath, string $cssUrlPath, array &$issues): string
{
    if (!is_file($cssPath)) {
        $issues[] = [
            'path' => $cssPath,
            'message' => 'Missing bootstrap-icons CSS for font lookup',
        ];
        return '';
    }
    $contents = @file_get_contents($cssPath);
    if ($contents === false) {
        $issues[] = [
            'path' => $cssPath,
            'message' => 'Unreadable bootstrap-icons CSS',
        ];
        return '';
    }
    $urls = assets_verify_extract_css_urls($contents);
    foreach ($urls as $rawUrl) {
        $clean = assets_verify_clean_css_url($rawUrl);
        if ($clean === '' || str_starts_with($clean, 'data:') || assets_verify_is_external_url($clean)) {
            continue;
        }
        $pathPart = assets_verify_strip_query_fragment($clean);
        if (strtolower(pathinfo($pathPart, PATHINFO_EXTENSION)) !== 'woff2') {
            continue;
        }
        return assets_http_smoke_resolve_url_path($cssUrlPath, $clean);
    }

    $issues[] = [
        'path' => $cssPath,
        'message' => 'No woff2 font URL found in bootstrap-icons CSS',
    ];
    return '';
}

function assets_http_smoke_resolve_url_path(string $cssUrlPath, string $fontUrl): string
{
    $parts = parse_url($fontUrl);
    $path = is_array($parts) ? (string) ($parts['path'] ?? $fontUrl) : $fontUrl;
    $query = is_array($parts) && isset($parts['query']) ? '?' . $parts['query'] : '';
    $fragment = is_array($parts) && isset($parts['fragment']) ? '#' . $parts['fragment'] : '';

    if ($path === '') {
        return '';
    }
    if (str_starts_with($path, '/')) {
        return $path . $query . $fragment;
    }

    $baseDir = rtrim(dirname($cssUrlPath), '/');
    $joined = $baseDir . '/' . ltrim($path, '/');
    $normalized = assets_http_smoke_normalize_path($joined);
    if ($normalized === '') {
        return '';
    }
    return $normalized . $query . $fragment;
}

function assets_http_smoke_normalize_path(string $path): string
{
    $isAbsolute = str_starts_with($path, '/');
    $segments = explode('/', $path);
    $stack = [];
    foreach ($segments as $segment) {
        if ($segment === '' || $segment === '.') {
            continue;
        }
        if ($segment === '..') {
            array_pop($stack);
            continue;
        }
        $stack[] = $segment;
    }
    $out = implode('/', $stack);
    if ($isAbsolute) {
        $out = '/' . $out;
    }
    return $out;
}

/**
 * @return null|array<string, array{status: int, headers: array<int|string, string>, body: string}>
 */
function assets_http_smoke_load_fixture(string $path): ?array
{
    if (!is_file($path)) {
        return null;
    }
    $data = require $path;
    return is_array($data) ? $data : null;
}

/**
 * @param array<string, array{status: int, headers: array<int|string, string>, body: string}> $fixture
 */
function assets_http_smoke_fixture_sender(array $fixture): callable
{
    return static function (string $method, string $url, array $headers, ?string $body, array $options) use ($fixture): array {
        if (!isset($fixture[$url])) {
            throw new RuntimeException('fixture_missing_response');
        }
        $response = $fixture[$url];
        return [
            'status' => (int) ($response['status'] ?? 0),
            'headers' => is_array($response['headers'] ?? null) ? $response['headers'] : [],
            'body' => (string) ($response['body'] ?? ''),
        ];
    };
}

function assets_http_smoke_content_type_ok(string $contentType, string $kind): bool
{
    $contentType = strtolower($contentType);
    if ($kind === 'font_woff2') {
        return $contentType !== '' && (
            str_contains($contentType, 'font/woff2')
            || str_contains($contentType, 'application/font-woff2')
            || str_contains($contentType, 'application/octet-stream')
        );
    }
    if (str_ends_with($kind, '_css')) {
        return $contentType !== '' && str_contains($contentType, 'text/css');
    }
    return $contentType !== '' && str_contains($contentType, 'javascript');
}

/**
 * @return array<int, string>
 */
function assets_http_smoke_body_issues(string $body, string $kind): array
{
    if ($body === '') {
        return ['Empty response body'];
    }
    $issues = [];
    $trimmed = ltrim($body);

    if ($kind === 'htmx_js') {
        if (!assets_http_smoke_starts_with_any($trimmed, ['(function', '/*!'])) {
            $issues[] = 'Unexpected htmx body signature';
        }
        return $issues;
    }
    if ($kind === 'bootstrap_js') {
        if (!assets_http_smoke_starts_with_any($trimmed, ['(()=>{', '/*!'])) {
            $issues[] = 'Unexpected bootstrap JS signature';
        }
        return $issues;
    }
    if ($kind === 'bootstrap_css') {
        if (stripos($body, 'bootstrap') === false) {
            $issues[] = 'Missing bootstrap marker in CSS';
        }
        return $issues;
    }
    if ($kind === 'icons_css') {
        if (strpos($body, '.bi') === false) {
            $issues[] = 'Missing .bi marker in icons CSS';
        }
        return $issues;
    }
    if ($kind === 'font_woff2') {
        if (strlen($body) <= ASSETS_HTTP_SMOKE_FONT_MIN_BYTES) {
            $issues[] = 'Font payload too small';
        }
        $head = substr($body, 0, 200);
        if (stripos($head, 'vendor placeholder') !== false || stripos($head, 'placeholder') !== false) {
            $issues[] = 'Font payload contains placeholder text';
        }
        if (!assets_http_smoke_is_binary($body)) {
            $issues[] = 'Font payload does not look binary';
        }
        return $issues;
    }

    return $issues;
}

/**
 * @param array<int, string> $signatures
 */
function assets_http_smoke_starts_with_any(string $value, array $signatures): bool
{
    foreach ($signatures as $signature) {
        if (str_starts_with($value, $signature)) {
            return true;
        }
    }
    return false;
}

function assets_http_smoke_is_binary(string $body): bool
{
    $chunk = substr($body, 0, 64);
    if ($chunk === '') {
        return false;
    }
    return preg_match('/[^\x09\x0A\x0D\x20-\x7E]/', $chunk) === 1;
}

if (PHP_SAPI === 'cli' && realpath($argv[0] ?? '') === realpath(__FILE__)) {
    $args = $argv;
    array_shift($args);
    exit(assets_http_smoke_run(dirname(__DIR__), $args));
}
