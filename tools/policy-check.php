<?php
declare(strict_types=1);

/**
 * Policy checks for frontend separation rules.
 *
 * Usage:
 *   php tools/policy-check.php [path...]
 */

const POLICY_CDN_HOSTS = [
    'cdn.jsdelivr.net',
    'unpkg.com',
    'cdnjs.cloudflare.com',
    'fonts.googleapis.com',
    'googleapis',
];

const POLICY_W3_PATTERNS = [
    '/[\'"][A-Za-z0-9_]*_class[\'"]\\s*=>/',
    '/[\'"]class_[A-Za-z0-9_]+[\'"]\\s*=>/',
    '/\\[[\'"][A-Za-z0-9_]*_class[\'"]\\]\\s*=/',
    '/\\bstatus_class\\b\\s*=>/i',
    '/\\bbadge_class\\b\\s*=>/i',
];

/**
 * @param array<int, string> $paths
 * @return array<int, array{level: string, code: string, file: string, line: int, message: string, snippet: string}>
 */
function policy_check_paths(array $paths): array
{
    $findings = [];
    foreach ($paths as $path) {
        $path = rtrim($path, DIRECTORY_SEPARATOR);
        if ($path === '') {
            continue;
        }
        if (!file_exists($path)) {
            $findings[] = [
                'level' => 'error',
                'code' => 'R0',
                'file' => $path,
                'line' => 1,
                'message' => 'Path does not exist',
                'snippet' => '',
            ];
            continue;
        }
        if (is_dir($path)) {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS)
            );
            foreach ($iterator as $file) {
                if (!$file->isFile()) {
                    continue;
                }
                if (strtolower($file->getExtension()) !== 'html') {
                    continue;
                }
                $findings = array_merge($findings, policy_check_file($file->getPathname()));
            }
            continue;
        }
        $findings = array_merge($findings, policy_check_file($path));
    }

    return $findings;
}

/**
 * @param array<int, string> $paths
 * @return array<int, array{level: string, code: string, file: string, line: int, message: string, snippet: string}>
 */
function policy_check_php_paths(array $paths): array
{
    $findings = [];
    foreach ($paths as $path) {
        $path = rtrim($path, DIRECTORY_SEPARATOR);
        if ($path === '') {
            continue;
        }
        if (!file_exists($path)) {
            continue;
        }
        if (is_dir($path)) {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS)
            );
            foreach ($iterator as $file) {
                if (!$file->isFile()) {
                    continue;
                }
                if (strtolower($file->getExtension()) !== 'php') {
                    continue;
                }
                $findings = array_merge($findings, policy_check_php_file($file->getPathname()));
            }
            continue;
        }
        if (strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'php') {
            $findings = array_merge($findings, policy_check_php_file($path));
        }
    }

    return $findings;
}

/**
 * @return array<int, array{level: string, code: string, file: string, line: int, message: string, snippet: string}>
 */
function policy_check_php_file(string $path): array
{
    $contents = @file_get_contents($path);
    if ($contents === false) {
        return [];
    }

    $findings = [];
    foreach (POLICY_W3_PATTERNS as $pattern) {
        if (preg_match_all($pattern, $contents, $matches, PREG_OFFSET_CAPTURE) <= 0) {
            continue;
        }
        foreach ($matches[0] as $match) {
            $findings[] = policy_make_finding('warning', 'W3', $path, $match[1], 'Presentation leak in PHP data', $contents);
        }
    }

    return $findings;
}

/**
 * @return array<int, array{level: string, code: string, file: string, line: int, message: string, snippet: string}>
 */
function policy_check_file(string $path): array
{
    $contents = @file_get_contents($path);
    if ($contents === false) {
        return [[
            'level' => 'error',
            'code' => 'R0',
            'file' => $path,
            'line' => 1,
            'message' => 'Cannot read file',
            'snippet' => '',
        ]];
    }

    $findings = [];

    if (preg_match_all('/<style\\b/i', $contents, $matches, PREG_OFFSET_CAPTURE) > 0) {
        foreach ($matches[0] as $match) {
            $findings[] = policy_make_finding('error', 'R1', $path, $match[1], 'Inline <style> is forbidden', $contents);
        }
    }

    if (preg_match_all('/<script\\b[^>]*>/i', $contents, $matches, PREG_OFFSET_CAPTURE) > 0) {
        foreach ($matches[0] as $match) {
            $tag = $match[0];
            if (preg_match('/\\bsrc\\s*=\\s*/i', $tag) !== 1) {
                $findings[] = policy_make_finding('error', 'R1', $path, $match[1], 'Inline <script> is forbidden', $contents);
            }
        }
    }

    if (preg_match_all('/<script\\b[^>]*>(.*?)<\\/script>/is', $contents, $matches, PREG_OFFSET_CAPTURE) > 0) {
        foreach ($matches[0] as $idx => $match) {
            $body = $matches[1][$idx][0] ?? '';
            if (trim($body) !== '') {
                $findings[] = policy_make_finding('error', 'R1', $path, $match[1], 'Inline <script> body is forbidden', $contents);
            }
        }
    }

    foreach (POLICY_CDN_HOSTS as $host) {
        if (preg_match_all('/' . preg_quote($host, '/') . '/i', $contents, $matches, PREG_OFFSET_CAPTURE) <= 0) {
            continue;
        }
        foreach ($matches[0] as $match) {
            $findings[] = policy_make_finding('error', 'R2', $path, $match[1], 'External CDN usage is forbidden', $contents);
        }
    }

    if (preg_match_all('/\\bonclick\\s*=\\s*([\'"]).*?\\1/i', $contents, $matches, PREG_OFFSET_CAPTURE) > 0) {
        foreach ($matches[0] as $match) {
            $findings[] = policy_make_finding('warning', 'W1', $path, $match[1], 'Inline onclick attribute', $contents);
        }
    }

    if (preg_match_all('/\\bstyle\\s*=\\s*([\'"]).*?\\1/i', $contents, $matches, PREG_OFFSET_CAPTURE) > 0) {
        foreach ($matches[0] as $match) {
            $findings[] = policy_make_finding('warning', 'W2', $path, $match[1], 'Inline style attribute', $contents);
        }
    }

    return $findings;
}

function policy_line_from_offset(string $contents, int $offset): int
{
    if ($offset <= 0) {
        return 1;
    }
    return substr_count($contents, "\n", 0, $offset) + 1;
}

function policy_snippet_from_offset(string $contents, int $offset): string
{
    $before = $offset > 0 ? substr($contents, 0, $offset) : '';
    $lineStart = strrpos($before, "\n");
    $lineStart = $lineStart === false ? 0 : $lineStart + 1;
    $lineEnd = strpos($contents, "\n", $offset);
    if ($lineEnd === false) {
        $lineEnd = strlen($contents);
    }
    $line = substr($contents, $lineStart, $lineEnd - $lineStart);
    $line = trim(preg_replace('/\\s+/', ' ', $line) ?? $line);
    if (strlen($line) > 120) {
        $line = substr($line, 0, 117) . '...';
    }
    return $line;
}

function policy_make_finding(
    string $level,
    string $code,
    string $path,
    int $offset,
    string $message,
    string $contents
): array {
    return [
        'level' => $level,
        'code' => $code,
        'file' => $path,
        'line' => policy_line_from_offset($contents, $offset),
        'message' => $message,
        'snippet' => policy_snippet_from_offset($contents, $offset),
    ];
}

/**
 * @param array<int, string> $paths
 * @return array{errors: array<int, array{level: string, code: string, file: string, line: int, message: string, snippet: string}>, warnings: array<int, array{level: string, code: string, file: string, line: int, message: string, snippet: string}>}
 */
function policy_analyze(array $paths): array
{
    $findings = policy_check_paths($paths);
    $findings = array_merge($findings, policy_check_php_paths($paths));
    $errors = [];
    $warnings = [];
    foreach ($findings as $finding) {
        if (($finding['level'] ?? '') === 'warning') {
            $warnings[] = $finding;
        } else {
            $errors[] = $finding;
        }
    }

    return [
        'errors' => $errors,
        'warnings' => $warnings,
    ];
}

/**
 * @param array{errors: array<int, array{level: string, code: string, file: string, line: int, message: string, snippet: string}>, warnings: array<int, array{level: string, code: string, file: string, line: int, message: string, snippet: string}>} $analysis
 */
function policy_exit_code(array $analysis): int
{
    return count($analysis['errors']) > 0 ? 1 : 0;
}

/**
 * @param array<int, string> $paths
 */
function policy_run(array $paths): int
{
    $analysis = policy_analyze($paths);
    foreach ($analysis['errors'] as $error) {
        $snippet = $error['snippet'] !== '' ? (' | ' . $error['snippet']) : '';
        echo '[' . $error['code'] . '] ' . $error['file'] . ':' . $error['line'] . ' ' . $error['message'] . $snippet . "\n";
    }
    foreach ($analysis['warnings'] as $warning) {
        $snippet = $warning['snippet'] !== '' ? (' | ' . $warning['snippet']) : '';
        echo '[' . $warning['code'] . '] ' . $warning['file'] . ':' . $warning['line'] . $snippet . "\n";
    }

    $errorsCount = count($analysis['errors']);
    $warningsCount = count($analysis['warnings']);
    $w3Count = 0;
    foreach ($analysis['warnings'] as $warning) {
        if (($warning['code'] ?? '') === 'W3') {
            $w3Count++;
        }
    }
    echo 'Summary: errors=' . $errorsCount . ' warnings=' . $warningsCount . ' w3=' . $w3Count . "\n";

    return policy_exit_code($analysis);
}

if (PHP_SAPI === 'cli' && realpath($argv[0] ?? '') === realpath(__FILE__)) {
    $paths = $argv;
    array_shift($paths);
    if ($paths === []) {
        $paths = [
            __DIR__ . '/../themes',
            __DIR__ . '/../src',
            __DIR__ . '/../modules',
        ];
    }
    exit(policy_run($paths));
}
