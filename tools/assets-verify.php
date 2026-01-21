<?php
declare(strict_types=1);

/**
 * Verify allowlisted vendor assets to prevent placeholder deploys.
 *
 * Usage:
 *   php tools/cli.php assets:verify [--root=/path/to/root]
 */

const ASSETS_VERIFY_MIN_BYTES = [
    'js' => 10 * 1024,
    'css' => 5 * 1024,
    'font' => 5 * 1024,
];

function assets_verify_run(string $rootPath): int
{
    $root = assets_verify_root_path($rootPath);
    $configPath = $root . '/config/assets.php';
    if (!is_file($configPath)) {
        echo 'assets.verify.error ' . $configPath . ": missing config/assets.php\n";
        return 1;
    }

    $config = require $configPath;
    if (!is_array($config)) {
        echo 'assets.verify.error ' . $configPath . ": invalid config\n";
        return 1;
    }

    [$targets, $errors] = assets_verify_targets($root, $config);
    $issues = $errors;

    foreach ($targets as $target) {
        $path = $target['path'];
        $kind = $target['kind'];
        $minBytes = $target['min_bytes'] ?? null;
        foreach (assets_verify_file($path, $kind, is_int($minBytes) ? $minBytes : null) as $message) {
            $issues[] = [
                'path' => $path,
                'message' => $message,
            ];
        }
    }

    if ($issues === []) {
        echo "assets.verify.ok\n";
        return 0;
    }

    foreach ($issues as $issue) {
        $path = (string) ($issue['path'] ?? '');
        $message = (string) ($issue['message'] ?? '');
        echo 'assets.verify.error ' . $path . ': ' . $message . "\n";
    }

    return 1;
}

function assets_verify_root_path(string $rootPath): string
{
    $rootPath = rtrim($rootPath, '/\\');
    if ($rootPath === '') {
        $rootPath = dirname(__DIR__);
    }
    $real = realpath($rootPath);
    return $real !== false ? $real : $rootPath;
}

/**
 * @param array<string, mixed> $config
 * @return array{
 *   0: array<int, array{path: string, kind: string, min_bytes?: int}>,
 *   1: array<int, array{path: string, message: string}>
 * }
 */
function assets_verify_targets(string $root, array $config): array
{
    $targets = [];
    $errors = [];

    $map = [
        'htmx_js' => ['label' => 'htmx.min.js', 'kind' => 'js'],
        'bootstrap_css' => ['label' => 'bootstrap.min.css', 'kind' => 'css'],
        'bootstrap_js' => ['label' => 'bootstrap.bundle.min.js', 'kind' => 'js'],
        'bootstrap_icons_css' => ['label' => 'bootstrap-icons.min.css', 'kind' => 'css'],
    ];

    foreach ($map as $key => $meta) {
        $assetPath = $config[$key] ?? '';
        if (!is_string($assetPath) || trim($assetPath) === '') {
            $errors[] = [
                'path' => 'config/assets.php',
                'message' => 'Missing asset path for ' . $meta['label'],
            ];
            continue;
        }
        $path = assets_verify_local_path($root, $assetPath);
        $targets[] = ['path' => $path, 'kind' => $meta['kind']];

        if ($key === 'bootstrap_icons_css') {
            $fontTargets = assets_verify_bootstrap_icons_fonts($root, $path, $errors);
            foreach ($fontTargets as $fontTarget) {
                $targets[] = $fontTarget;
            }
        }
    }

    return [$targets, $errors];
}

/**
 * @param array<int, array{path: string, message: string}> $errors
 * @return array<int, array{path: string, kind: string, min_bytes?: int}>
 */
function assets_verify_bootstrap_icons_fonts(string $root, string $cssPath, array &$errors): array
{
    if (!is_file($cssPath)) {
        return [];
    }

    $contents = @file_get_contents($cssPath);
    if ($contents === false) {
        $errors[] = [
            'path' => $cssPath,
            'message' => 'Unreadable bootstrap-icons CSS',
        ];
        return [];
    }

    $urls = assets_verify_extract_css_urls($contents);
    if ($urls === []) {
        $errors[] = [
            'path' => $cssPath,
            'message' => 'No font URLs found in bootstrap-icons CSS',
        ];
        return [];
    }

    $targets = [];
    $seen = [];
    $woffPaths = [];

    foreach ($urls as $url) {
        $clean = assets_verify_clean_css_url($url);
        if ($clean === '') {
            continue;
        }
        if (str_starts_with($clean, 'data:')) {
            $errors[] = [
                'path' => $cssPath,
                'message' => 'Embedded font URL not allowed: ' . $clean,
            ];
            continue;
        }
        if (assets_verify_is_external_url($clean)) {
            $errors[] = [
                'path' => $cssPath,
                'message' => 'External font URL not allowed: ' . $clean,
            ];
            continue;
        }
        $pathPart = assets_verify_strip_query_fragment($clean);
        if ($pathPart === '') {
            continue;
        }
        $ext = strtolower(pathinfo($pathPart, PATHINFO_EXTENSION));
        if (!in_array($ext, ['woff', 'woff2'], true)) {
            $errors[] = [
                'path' => $cssPath,
                'message' => 'Unsupported font extension in URL: ' . $clean,
            ];
            continue;
        }
        $resolved = assets_verify_resolve_css_path($root, $cssPath, $pathPart);
        if (!isset($seen[$resolved])) {
            $targets[] = ['path' => $resolved, 'kind' => 'font', 'min_bytes' => 1024];
            $seen[$resolved] = true;
        }
        if ($ext === 'woff') {
            $woffPaths[] = $resolved;
        }
    }

    foreach ($woffPaths as $woffPath) {
        $candidate = assets_verify_woff2_candidate($woffPath);
        if ($candidate !== '' && !is_file($candidate)) {
            $errors[] = [
                'path' => $woffPath,
                'message' => 'Missing woff2 font for ' . basename($woffPath),
            ];
        }
    }

    return $targets;
}

function assets_verify_local_path(string $root, string $assetPath): string
{
    $path = assets_verify_extract_path($assetPath);
    $path = '/' . ltrim($path, '/');
    return rtrim($root, '/\\') . '/public' . $path;
}

function assets_verify_extract_path(string $assetPath): string
{
    $parts = parse_url($assetPath);
    if (is_array($parts) && isset($parts['path'])) {
        return (string) $parts['path'];
    }
    return $assetPath;
}

/**
 * @return array<int, string>
 */
function assets_verify_file(string $path, string $kind, ?int $minBytes = null): array
{
    if (!is_file($path)) {
        return ['Missing file'];
    }

    $errors = [];
    $size = filesize($path);
    if ($size === false) {
        $errors[] = 'Unreadable file size';
    } else {
        $min = $minBytes ?? assets_verify_min_bytes($kind);
        if ($min !== null && $size < $min) {
            $errors[] = 'File size ' . $size . ' bytes is below ' . $min . ' bytes';
        }
    }

    $contents = file_get_contents($path);
    if ($contents === false) {
        $errors[] = 'Unreadable file contents';
        return $errors;
    }

    if (stripos($contents, 'vendor placeholder') !== false) {
        $errors[] = 'Contains vendor placeholder';
    }
    $head = substr($contents, 0, 200);
    if (stripos($head, 'todo') !== false) {
        $errors[] = 'Contains todo marker in first 200 bytes';
    }
    if (stripos($head, 'placeholder') !== false) {
        $errors[] = 'Contains placeholder in first 200 bytes';
    }

    return $errors;
}

function assets_verify_min_bytes(string $kind): ?int
{
    return ASSETS_VERIFY_MIN_BYTES[$kind] ?? null;
}

/**
 * @return array<int, string>
 */
function assets_verify_extract_css_urls(string $contents): array
{
    if (preg_match_all('/url\\(([^)]+)\\)/i', $contents, $matches) <= 0) {
        return [];
    }
    $urls = [];
    foreach ($matches[1] as $raw) {
        if (!is_string($raw)) {
            continue;
        }
        $urls[] = $raw;
    }
    return $urls;
}

function assets_verify_clean_css_url(string $value): string
{
    $value = trim($value);
    if ($value === '') {
        return '';
    }
    $value = trim($value, " \t\n\r\0\x0B\"'");
    return trim($value);
}

function assets_verify_strip_query_fragment(string $value): string
{
    $value = trim($value);
    if ($value === '') {
        return '';
    }
    $stripped = strtok($value, '?#');
    return is_string($stripped) ? $stripped : '';
}

function assets_verify_is_external_url(string $value): bool
{
    if (str_starts_with($value, '//')) {
        return true;
    }
    return preg_match('/^[a-z][a-z0-9+.-]*:/i', $value) === 1;
}

function assets_verify_resolve_css_path(string $root, string $cssPath, string $assetPath): string
{
    if (str_starts_with($assetPath, '/')) {
        return rtrim($root, '/\\') . '/public' . $assetPath;
    }
    return rtrim(dirname($cssPath), '/\\') . '/' . $assetPath;
}

function assets_verify_woff2_candidate(string $woffPath): string
{
    if (!str_ends_with(strtolower($woffPath), '.woff')) {
        return '';
    }
    $base = substr($woffPath, 0, -5);
    return $base . '.woff2';
}
