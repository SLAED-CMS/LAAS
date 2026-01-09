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

/**
 * @param array<int, string> $paths
 * @return array<int, array{file: string, line: int, rule: string, message: string}>
 */
function policy_check_paths(array $paths): array
{
    $violations = [];
    foreach ($paths as $path) {
        $path = rtrim($path, DIRECTORY_SEPARATOR);
        if ($path === '') {
            continue;
        }
        if (!file_exists($path)) {
            $violations[] = [
                'file' => $path,
                'line' => 1,
                'rule' => 'R0',
                'message' => 'Path does not exist',
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
                $violations = array_merge($violations, policy_check_file($file->getPathname()));
            }
            continue;
        }
        $violations = array_merge($violations, policy_check_file($path));
    }

    return $violations;
}

/**
 * @return array<int, array{file: string, line: int, rule: string, message: string}>
 */
function policy_check_file(string $path): array
{
    $contents = @file_get_contents($path);
    if ($contents === false) {
        return [[
            'file' => $path,
            'line' => 1,
            'rule' => 'R0',
            'message' => 'Cannot read file',
        ]];
    }

    $violations = [];

    if (preg_match_all('/<style\\b/i', $contents, $matches, PREG_OFFSET_CAPTURE) > 0) {
        foreach ($matches[0] as $match) {
            $violations[] = [
                'file' => $path,
                'line' => policy_line_from_offset($contents, $match[1]),
                'rule' => 'R1',
                'message' => 'Inline <style> is forbidden',
            ];
        }
    }

    if (preg_match_all('/<script\\b[^>]*>/i', $contents, $matches, PREG_OFFSET_CAPTURE) > 0) {
        foreach ($matches[0] as $match) {
            $tag = $match[0];
            if (preg_match('/\\bsrc\\s*=\\s*/i', $tag) !== 1) {
                $violations[] = [
                    'file' => $path,
                    'line' => policy_line_from_offset($contents, $match[1]),
                    'rule' => 'R1',
                    'message' => 'Inline <script> is forbidden',
                ];
            }
        }
    }

    if (preg_match_all('/<script\\b[^>]*>(.*?)<\\/script>/is', $contents, $matches, PREG_OFFSET_CAPTURE) > 0) {
        foreach ($matches[0] as $idx => $match) {
            $body = $matches[1][$idx][0] ?? '';
            if (trim($body) !== '') {
                $violations[] = [
                    'file' => $path,
                    'line' => policy_line_from_offset($contents, $match[1]),
                    'rule' => 'R1',
                    'message' => 'Inline <script> body is forbidden',
                ];
            }
        }
    }

    foreach (POLICY_CDN_HOSTS as $host) {
        if (preg_match_all('/' . preg_quote($host, '/') . '/i', $contents, $matches, PREG_OFFSET_CAPTURE) <= 0) {
            continue;
        }
        foreach ($matches[0] as $match) {
            $violations[] = [
                'file' => $path,
                'line' => policy_line_from_offset($contents, $match[1]),
                'rule' => 'R2',
                'message' => 'External CDN usage is forbidden',
            ];
        }
    }

    return $violations;
}

function policy_line_from_offset(string $contents, int $offset): int
{
    if ($offset <= 0) {
        return 1;
    }
    return substr_count($contents, "\n", 0, $offset) + 1;
}

/**
 * @param array<int, string> $paths
 */
function policy_run(array $paths): int
{
    $violations = policy_check_paths($paths);
    $count = count($violations);
    if ($count === 0) {
        echo "Policy check OK (0 violations)\n";
        return 0;
    }

    foreach ($violations as $violation) {
        $file = $violation['file'];
        $line = $violation['line'];
        $rule = $violation['rule'];
        $message = $violation['message'];
        echo $file . ':' . $line . ' ' . $rule . ' ' . $message . "\n";
    }
    echo 'Summary: ' . $count . " violation(s)\n";
    return 1;
}

if (PHP_SAPI === 'cli' && realpath($argv[0] ?? '') === realpath(__FILE__)) {
    $paths = $argv;
    array_shift($paths);
    if ($paths === []) {
        $paths = [__DIR__ . '/../themes'];
    }
    exit(policy_run($paths));
}
