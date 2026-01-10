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

const POLICY_W3A_PATTERNS = [
    '/[\'"][A-Za-z0-9_]*_class[\'"]\\s*=>/',
    '/\\[[\'"][A-Za-z0-9_]*_class[\'"]\\]\\s*=/',
    '/\\bstatus_class\\b\\s*=>/i',
    '/\\bbadge_class\\b\\s*=>/i',
];

const POLICY_W3B_PATTERNS = [
    '/[\'"]class_[A-Za-z0-9_]+[\'"]\\s*=>/i',
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

function policy_root_path(): string
{
    $root = realpath(__DIR__ . '/../');
    return $root !== false ? $root : dirname(__DIR__, 1);
}

function policy_normalize_path(string $path): string
{
    $real = realpath($path);
    $path = $real !== false ? $real : $path;
    return str_replace('\\', '/', $path);
}

/**
 * @return array<int, string>
 */
function policy_w3_excludes(): array
{
    $root = policy_root_path();
    $excludes = [
        $root . '/vendor',
        $root . '/storage',
        $root . '/public/assets',
        $root . '/docs',
        $root . '/themes',
        $root . '/tests/fixtures',
    ];

    $configPath = $root . '/config/policy.php';
    if (is_file($configPath)) {
        $config = require $configPath;
        if (is_array($config)) {
            $extra = $config['w3_exclude'] ?? [];
            if (is_array($extra)) {
                $excludes = array_merge($excludes, $extra);
            }
        }
    }

    $env = $_ENV['POLICY_W3_EXCLUDE'] ?? '';
    if (is_string($env) && trim($env) !== '') {
        $parts = array_filter(array_map('trim', explode(',', $env)));
        foreach ($parts as $part) {
            if ($part === '') {
                continue;
            }
            if (!str_starts_with($part, '/') && !preg_match('/^[A-Za-z]:[\\/]/', $part)) {
                $part = $root . '/' . $part;
            }
            $excludes[] = $part;
        }
    }

    return array_map('policy_normalize_path', $excludes);
}

function policy_w3_is_excluded(string $path): bool
{
    $path = policy_normalize_path($path);
    foreach (policy_w3_excludes() as $exclude) {
        $exclude = rtrim($exclude, '/');
        if ($exclude === '') {
            continue;
        }
        if ($path === $exclude || str_starts_with($path, $exclude . '/')) {
            return true;
        }
    }
    return false;
}

function policy_strip_comments_and_heredoc(string $contents): string
{
    $tokens = token_get_all($contents);
    $out = '';
    $inHeredoc = false;
    foreach ($tokens as $token) {
        if (is_array($token)) {
            $id = $token[0];
            $text = $token[1];
            if ($id === T_START_HEREDOC) {
                $inHeredoc = true;
                continue;
            }
            if ($id === T_END_HEREDOC) {
                $inHeredoc = false;
                continue;
            }
            if ($inHeredoc) {
                continue;
            }
            if ($id === T_COMMENT || $id === T_DOC_COMMENT) {
                continue;
            }
            $out .= $text;
            continue;
        }
        if ($inHeredoc) {
            continue;
        }
        $out .= $token;
    }
    return $out;
}

function policy_env_bool(string $key, bool $default): bool
{
    $value = $_ENV[$key] ?? null;
    if ($value === null || $value === '') {
        return $default;
    }
    $parsed = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    return $parsed ?? $default;
}

function policy_strict(): bool
{
    return policy_env_bool('POLICY_STRICT', false);
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
                $filePath = $file->getPathname();
                if (policy_w3_is_excluded($filePath)) {
                    continue;
                }
                $findings = array_merge($findings, policy_check_php_file($filePath));
            }
            continue;
        }
        if (strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'php') {
            if (policy_w3_is_excluded($path)) {
                continue;
            }
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
    $scanned = policy_strip_comments_and_heredoc($contents);

    foreach (POLICY_W3A_PATTERNS as $pattern) {
        if (preg_match_all($pattern, $scanned, $matches, PREG_OFFSET_CAPTURE) <= 0) {
            continue;
        }
        foreach ($matches[0] as $match) {
            $findings[] = policy_make_finding('warning', 'W3a', $path, $match[1], 'Presentation leak in PHP data (explicit class key)', $contents);
        }
    }

    foreach (POLICY_W3B_PATTERNS as $pattern) {
        if (preg_match_all($pattern, $scanned, $matches, PREG_OFFSET_CAPTURE) <= 0) {
            continue;
        }
        foreach ($matches[0] as $match) {
            $findings[] = policy_make_finding('warning', 'W3b', $path, $match[1], 'Presentation leak in PHP data (suspicious key)', $contents);
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
    if (count($analysis['errors']) > 0) {
        return 1;
    }
    if (policy_strict()) {
        foreach ($analysis['warnings'] as $warning) {
            if (($warning['code'] ?? '') === 'W3a') {
                return 1;
            }
        }
    }
    return 0;
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
    $w3aCount = 0;
    $w3bCount = 0;
    foreach ($analysis['warnings'] as $warning) {
        $code = (string) ($warning['code'] ?? '');
        if ($code === 'W3a') {
            $w3aCount++;
        }
        if ($code === 'W3b') {
            $w3bCount++;
        }
    }
    echo 'Summary: errors=' . $errorsCount . ' warnings=' . $warningsCount . ' w3a=' . $w3aCount . ' w3b=' . $w3bCount . "\n";

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
