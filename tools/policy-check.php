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
 * @return array<int, array{level: string, code: string, file: string, line: int, message: string, snippet: string}>
 */
function policy_check_git_hygiene(?string $root = null, ?string $nulMarker = null): array
{
    $findings = [];
    $root = $root ?? policy_root_path();
    $root = rtrim($root, '/\\');
    $marker = $nulMarker ?? 'nul';
    $extensions = policy_git_hygiene_extensions();

    $entries = @scandir($root);
    $entryLookup = null;
    if (is_array($entries)) {
        $entryLookup = array_fill_keys($entries, true);
    }

    foreach (policy_git_hygiene_markers($marker) as $name) {
        $path = $root . '/' . $name;
        $exists = $entryLookup !== null ? isset($entryLookup[$name]) : file_exists($path);
        if (!$exists) {
            continue;
        }
        $findings[] = [
            'level' => 'error',
            'code' => 'G1',
            'file' => $path,
            'line' => 1,
            'message' => 'git.hygiene: forbidden file name "' . $name . '" present',
            'snippet' => '',
        ];
    }

    $attributesPath = $root . '/.gitattributes';
    if (!is_file($attributesPath)) {
        $findings[] = [
            'level' => 'error',
            'code' => 'G2',
            'file' => $attributesPath,
            'line' => 1,
            'message' => 'git.hygiene: missing .gitattributes (LF enforcement required)',
            'snippet' => '',
        ];
        $attributesOk = false;
    } else {
        $missing = policy_gitattributes_missing_lf($attributesPath, $extensions);
        if ($missing !== []) {
            $findings[] = [
                'level' => 'error',
                'code' => 'G2',
                'file' => $attributesPath,
                'line' => 1,
                'message' => 'git.hygiene: missing eol=lf for ' . implode(', ', $missing),
                'snippet' => '',
            ];
            $attributesOk = false;
        } else {
            $attributesOk = true;
        }
    }

    $crlfFiles = policy_git_crlf_files($root, $extensions);
    foreach ($crlfFiles as $path) {
        $findings[] = [
            'level' => 'error',
            'code' => 'G3',
            'file' => $path,
            'line' => 1,
            'message' => 'git.hygiene: CRLF detected in tracked file',
            'snippet' => '',
        ];
    }

    $autocrlf = policy_git_autocrlf_value($root);
    if ($autocrlf !== null && strtolower($autocrlf) === 'true' && $attributesOk && $crlfFiles === []) {
        $message = 'git.hygiene: core.autocrlf=true; enforced by .gitattributes; working tree OK';
        $findings[] = [
            'level' => 'info',
            'code' => 'GI',
            'file' => '',
            'line' => 1,
            'message' => $message,
            'snippet' => '',
        ];
    }

    return $findings;
}

/**
 * @return array<int, string>
 */
function policy_git_hygiene_markers(string $marker): array
{
    $markers = [$marker];
    if (strtolower($marker) === 'nul') {
        $markers[] = 'NUL';
    }
    return array_values(array_unique($markers));
}

/**
 * @return array<int, string>
 */
function policy_git_hygiene_extensions(): array
{
    return ['php', 'md', 'html', 'js', 'css'];
}

/**
 * @param array<int, string> $extensions
 * @return array<int, string>
 */
function policy_gitattributes_missing_lf(string $path, array $extensions): array
{
    $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!is_array($lines)) {
        return $extensions;
    }

    $globalLf = false;
    $covered = array_fill_keys($extensions, false);

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        if (preg_match('/^\\*\\s+.*\\beol=lf\\b/i', $line) === 1) {
            $globalLf = true;
        }
        foreach ($extensions as $ext) {
            if (stripos($line, '*.' . $ext) === false) {
                continue;
            }
            if (stripos($line, 'eol=lf') !== false) {
                $covered[$ext] = true;
            }
        }
    }

    if ($globalLf) {
        return [];
    }

    $missing = [];
    foreach ($covered as $ext => $ok) {
        if (!$ok) {
            $missing[] = '*.' . $ext;
        }
    }

    return $missing;
}

function policy_git_autocrlf_value(string $root): ?string
{
    $override = $_ENV['POLICY_GIT_AUTOCRLF'] ?? null;
    if (is_string($override) && trim($override) !== '') {
        $value = trim($override);
        return strtolower($value) === 'unset' ? null : $value;
    }
    if (!function_exists('exec')) {
        return null;
    }
    $cmd = 'git -C ' . escapeshellarg($root) . ' config --get core.autocrlf' . policy_git_silence_suffix();
    $output = [];
    $code = 0;
    @exec($cmd, $output, $code);
    if ($code !== 0) {
        return null;
    }
    $value = trim(implode("\n", $output));
    return $value === '' ? null : $value;
}

/**
 * @param array<int, string> $extensions
 * @return array<int, string>
 */
function policy_git_crlf_files(string $root, array $extensions): array
{
    $files = policy_git_tracked_files($root);
    $crlf = [];

    foreach ($files as $file) {
        if (!policy_git_has_extension($file, $extensions)) {
            continue;
        }
        $path = $root . '/' . ltrim($file, '/');
        if (!is_file($path)) {
            continue;
        }
        $contents = @file_get_contents($path);
        if ($contents === false) {
            continue;
        }
        if (strpos($contents, "\r") !== false) {
            $crlf[] = $path;
        }
    }

    return $crlf;
}

function policy_git_has_extension(string $path, array $extensions): bool
{
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    return $ext !== '' && in_array($ext, $extensions, true);
}

/**
 * @return array<int, string>
 */
function policy_git_tracked_files(string $root): array
{
    $override = $_ENV['POLICY_GIT_TRACKED_FILES'] ?? null;
    if (is_string($override) && trim($override) !== '') {
        $raw = str_replace([';', "\r\n", "\r"], ["\n", "\n", "\n"], $override);
        $parts = array_filter(array_map('trim', explode("\n", $raw)));
        return array_values(array_unique($parts));
    }

    if (function_exists('exec')) {
        $cmd = 'git -C ' . escapeshellarg($root) . ' ls-files' . policy_git_silence_suffix();
        $output = [];
        $code = 0;
        @exec($cmd, $output, $code);
        if ($code === 0) {
            $files = array_values(array_filter(array_map('trim', $output), static fn(string $value): bool => $value !== ''));
            if ($files !== []) {
                return $files;
            }
        }
    }

    return policy_git_fallback_files($root);
}

function policy_git_silence_suffix(): string
{
    return stripos(PHP_OS_FAMILY, 'Windows') !== false ? ' 2>nul' : ' 2>/dev/null';
}

/**
 * @return array<int, string>
 */
function policy_git_fallback_files(string $root): array
{
    $root = rtrim($root, '/\\');
    $files = [];

    if (!is_dir($root)) {
        return $files;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
    );

    foreach ($iterator as $file) {
        if (!$file->isFile()) {
            continue;
        }
        $path = policy_normalize_path($file->getPathname());
        if (str_contains($path, '/.git/')
            || str_contains($path, '/vendor/')
            || str_contains($path, '/storage/')
            || str_contains($path, '/public/assets/')) {
            continue;
        }
        $files[] = policy_git_relative_path($root, $path);
    }

    return array_values(array_unique($files));
}

function policy_git_relative_path(string $root, string $path): string
{
    $root = rtrim(policy_normalize_path($root), '/');
    $path = policy_normalize_path($path);
    if (str_starts_with($path, $root . '/')) {
        return substr($path, strlen($root) + 1);
    }
    return $path;
}

/**
 * @param array<int, string> $paths
 * @return array<int, string>
 */
function policy_theme_roots(array $paths): array
{
    $roots = [];
    foreach ($paths as $path) {
        $path = rtrim($path, DIRECTORY_SEPARATOR);
        if ($path === '' || !is_dir($path)) {
            continue;
        }
        if (basename($path) === 'themes') {
            $roots[] = $path;
            continue;
        }
        $themes = $path . DIRECTORY_SEPARATOR . 'themes';
        if (is_dir($themes)) {
            $roots[] = $themes;
        }
    }

    $unique = [];
    $seen = [];
    foreach ($roots as $root) {
        $root = policy_normalize_path($root);
        if (isset($seen[$root])) {
            continue;
        }
        $seen[$root] = true;
        $unique[] = $root;
    }

    return $unique;
}

/**
 * @param array<int, string> $paths
 * @return array<int, array{level: string, code: string, file: string, line: int, message: string, snippet: string}>
 */
function policy_check_theme_layouts(array $paths): array
{
    $findings = [];
    foreach (policy_theme_roots($paths) as $root) {
        $items = @scandir($root);
        if (!is_array($items)) {
            continue;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $themePath = $root . '/' . $item;
            if (!is_dir($themePath)) {
                continue;
            }
            $basePath = $themePath . '/layouts/base.html';
            if (!is_file($basePath)) {
                $findings[] = policy_make_finding(
                    'warning',
                    'W6',
                    $basePath,
                    0,
                    'Missing canonical layout layouts/base.html',
                    ''
                );
            }
        }
    }
    return $findings;
}

/**
 * @param array<int, string> $paths
 * @return array<int, array{level: string, code: string, file: string, line: int, message: string, snippet: string}>
 */
function policy_check_theme_manifests(array $paths): array
{
    $findings = [];
    $root = policy_root_path();
    $autoload = $root . '/vendor/autoload.php';
    if (is_file($autoload)) {
        require_once $autoload;
    }

    if (!class_exists('\\Laas\\Theme\\ThemeValidator')) {
        return $findings;
    }

    foreach (policy_theme_roots($paths) as $themesRoot) {
        $items = @scandir($themesRoot);
        if (!is_array($items)) {
            continue;
        }
        $validator = new \Laas\Theme\ThemeValidator($themesRoot);
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $themePath = $themesRoot . '/' . $item;
            if (!is_dir($themePath)) {
                continue;
            }
            $result = $validator->validateTheme($item);
            if (!$result->hasViolations()) {
                continue;
            }
            foreach ($result->getViolations() as $violation) {
                $file = (string) ($violation['file'] ?? $themePath);
                $message = 'Theme ' . $item . ': ' . (string) ($violation['message'] ?? '');
                $findings[] = [
                    'level' => 'error',
                    'code' => 'T1',
                    'file' => $file,
                    'line' => 1,
                    'message' => $message,
                    'snippet' => '',
                ];
            }
        }
    }

    return $findings;
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

function policy_core_theme_strict(): bool
{
    return policy_env_bool('POLICY_CORE_THEME_STRICT', false);
}

/**
 * @return array{assets: int, http: int}
 */
function policy_run_assets_checks(): array
{
    $root = policy_root_path();
    require_once $root . '/tools/assets-verify.php';
    require_once $root . '/tools/assets-http-smoke.php';

    $assetsCode = assets_verify_run($root);

    $env = strtolower((string) ($_ENV['APP_ENV'] ?? ''));
    $httpSmoke = false;
    if ($env === 'local') {
        $httpSmoke = true;
    } else {
        $policySmoke = $_ENV['POLICY_HTTP_SMOKE'] ?? '';
        $httpSmoke = filter_var($policySmoke, FILTER_VALIDATE_BOOLEAN) === true;
    }

    if ($httpSmoke) {
        $httpCode = assets_http_smoke_run($root, []);
    } else {
        $httpCode = 0;
        echo "assets.http_smoke.skipped (set POLICY_HTTP_SMOKE=1)\n";
    }

    return ['assets' => $assetsCode, 'http' => $httpCode];
}

function policy_is_core_theme_path(string $path): bool
{
    $root = policy_root_path();
    $path = policy_normalize_path($path);
    $default = policy_normalize_path($root . '/themes/default');
    $admin = policy_normalize_path($root . '/themes/admin');

    if (str_starts_with($path, $default . '/') || $path === $default
        || str_starts_with($path, $admin . '/') || $path === $admin) {
        return true;
    }

    return str_contains($path, '/themes/default/')
        || str_contains($path, '/themes/admin/')
        || str_ends_with($path, '/themes/default')
        || str_ends_with($path, '/themes/admin');
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
            $offset = (int) $match[1];
            $findings[] = policy_make_finding('warning', 'W3a', $path, $offset, 'Presentation leak in PHP data (explicit class key)', $contents);
        }
    }

    foreach (POLICY_W3B_PATTERNS as $pattern) {
        if (preg_match_all($pattern, $scanned, $matches, PREG_OFFSET_CAPTURE) <= 0) {
            continue;
        }
        foreach ($matches[0] as $match) {
            $offset = (int) $match[1];
            $findings[] = policy_make_finding('warning', 'W3b', $path, $offset, 'Presentation leak in PHP data (suspicious key)', $contents);
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

    if (preg_match_all('/<style\\b/i', $contents, $matches, PREG_OFFSET_CAPTURE) > 0) {
        foreach ($matches[0] as $match) {
            $findings[] = policy_make_finding('warning', 'W5', $path, $match[1], 'Inline <style> tag', $contents);
        }
    }

    if (preg_match_all('/<script\\b[^>]*>/i', $contents, $matches, PREG_OFFSET_CAPTURE) > 0) {
        foreach ($matches[0] as $match) {
            $tag = $match[0];
            if (preg_match('/\\bsrc\\s*=\\s*/i', $tag) === 1) {
                continue;
            }
            $findings[] = policy_make_finding('warning', 'W5', $path, $match[1], 'Inline <script> tag', $contents);
        }
    }

    if (preg_match_all('/https:\\/\\/cdn\\./i', $contents, $matches, PREG_OFFSET_CAPTURE) > 0) {
        foreach ($matches[0] as $match) {
            $findings[] = policy_make_finding('warning', 'W4', $path, $match[1], 'CDN link in template', $contents);
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
 * @return array{
 *   errors: array<int, array{level: string, code: string, file: string, line: int, message: string, snippet: string}>,
 *   warnings: array<int, array{level: string, code: string, file: string, line: int, message: string, snippet: string}>,
 *   info: array<int, array{level: string, code: string, file: string, line: int, message: string, snippet: string}>
 * }
 */
function policy_analyze(array $paths): array
{
    $findings = policy_check_git_hygiene();
    $findings = array_merge($findings, policy_check_paths($paths));
    $findings = array_merge($findings, policy_check_php_paths($paths));
    $findings = array_merge($findings, policy_check_theme_layouts($paths));
    $findings = array_merge($findings, policy_check_theme_manifests($paths));
    $errors = [];
    $warnings = [];
    $info = [];
    foreach ($findings as $finding) {
        $level = $finding['level'] ?? '';
        if ($level === 'info') {
            $info[] = $finding;
        } elseif ($level === 'warning') {
            $warnings[] = $finding;
        } else {
            $errors[] = $finding;
        }
    }

    if (policy_core_theme_strict()) {
        $strictWarnings = [];
        foreach ($warnings as $warning) {
            $code = (string) ($warning['code'] ?? '');
            $file = (string) ($warning['file'] ?? '');
            if (in_array($code, ['W4', 'W5', 'W6'], true) && policy_is_core_theme_path($file)) {
                $errors[] = array_merge($warning, ['level' => 'error']);
                continue;
            }
            $strictWarnings[] = $warning;
        }
        $warnings = $strictWarnings;
    }

    return [
        'errors' => $errors,
        'warnings' => $warnings,
        'info' => $info,
    ];
}

/**
 * @param array{
 *   errors: array<int, array{level: string, code: string, file: string, line: int, message: string, snippet: string}>,
 *   warnings: array<int, array{level: string, code: string, file: string, line: int, message: string, snippet: string}>,
 *   info: array<int, array{level: string, code: string, file: string, line: int, message: string, snippet: string}>
 * } $analysis
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
    $assetsResult = policy_run_assets_checks();
    $analysis = policy_analyze($paths);
    foreach ($analysis['errors'] as $error) {
        $snippet = $error['snippet'] !== '' ? (' | ' . $error['snippet']) : '';
        echo '[' . $error['code'] . '] ' . $error['file'] . ':' . $error['line'] . ' ' . $error['message'] . $snippet . "\n";
    }
    foreach ($analysis['warnings'] as $warning) {
        $snippet = $warning['snippet'] !== '' ? (' | ' . $warning['snippet']) : '';
        echo '[' . $warning['code'] . '] ' . $warning['file'] . ':' . $warning['line'] . $snippet . "\n";
    }
    foreach ($analysis['info'] as $info) {
        $message = (string) ($info['message'] ?? '');
        if ($message === '') {
            continue;
        }
        echo '[' . $info['code'] . '] ' . $message . "\n";
    }

    $errorsCount = count($analysis['errors']);
    $warningsCount = count($analysis['warnings']);
    $w3aCount = 0;
    $w3bCount = 0;
    $w4Count = 0;
    $w5Count = 0;
    $w6Count = 0;
    foreach ($analysis['warnings'] as $warning) {
        $code = (string) ($warning['code'] ?? '');
        if ($code === 'W3a') {
            $w3aCount++;
        }
        if ($code === 'W3b') {
            $w3bCount++;
        }
        if ($code === 'W4') {
            $w4Count++;
        }
        if ($code === 'W5') {
            $w5Count++;
        }
        if ($code === 'W6') {
            $w6Count++;
        }
    }
    echo 'Summary: errors=' . $errorsCount . ' warnings=' . $warningsCount . ' w3a=' . $w3aCount . ' w3b=' . $w3bCount . ' w4=' . $w4Count . ' w5=' . $w5Count . ' w6=' . $w6Count . "\n";

    return max($assetsResult['assets'], $assetsResult['http'], policy_exit_code($analysis));
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
