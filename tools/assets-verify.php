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
        foreach (assets_verify_file($path, $kind) as $message) {
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
 *   0: array<int, array{path: string, kind: string}>,
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
            $fontsDir = rtrim(dirname($path), '/\\') . '/fonts';
            $fontTargets = assets_verify_fonts($fontsDir, $errors);
            foreach ($fontTargets as $fontPath) {
                $targets[] = ['path' => $fontPath, 'kind' => 'font'];
            }
        }
    }

    return [$targets, $errors];
}

/**
 * @param array<int, array{path: string, message: string}> $errors
 * @return array<int, string>
 */
function assets_verify_fonts(string $fontsDir, array &$errors): array
{
    if (!is_dir($fontsDir)) {
        $errors[] = [
            'path' => $fontsDir,
            'message' => 'Missing fonts directory',
        ];
        return [];
    }
    $fonts = glob($fontsDir . '/*.woff2');
    if ($fonts === false || $fonts === []) {
        $errors[] = [
            'path' => $fontsDir . '/*.woff2',
            'message' => 'Missing woff2 font files',
        ];
        return [];
    }
    sort($fonts);
    return $fonts;
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
function assets_verify_file(string $path, string $kind): array
{
    if (!is_file($path)) {
        return ['Missing file'];
    }

    $errors = [];
    $size = filesize($path);
    if ($size === false) {
        $errors[] = 'Unreadable file size';
    } else {
        $min = assets_verify_min_bytes($kind);
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
        $errors[] = 'Contains TODO in first 200 bytes';
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
