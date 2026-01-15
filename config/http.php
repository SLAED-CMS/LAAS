<?php
declare(strict_types=1);

$env = $_ENV;
$envInt = static function (string $key, int $default) use ($env): int {
    $value = $env[$key] ?? null;
    if ($value === null || $value === '') {
        return $default;
    }
    if (!is_numeric($value)) {
        return $default;
    }
    return (int) $value;
};
$envList = static function (string $key, array $default) use ($env): array {
    $value = $env[$key] ?? null;
    if ($value === null || $value === '') {
        return $default;
    }
    $parts = array_filter(array_map('trim', explode(',', (string) $value)));
    return $parts !== [] ? array_values($parts) : $default;
};

return [
    'max_body_bytes' => $envInt('HTTP_MAX_BODY_BYTES', 2_000_000),
    'max_post_fields' => $envInt('HTTP_MAX_POST_FIELDS', 200),
    'max_header_bytes' => $envInt('HTTP_MAX_HEADER_BYTES', 32_000),
    'max_url_length' => $envInt('HTTP_MAX_URL_LENGTH', 2048),
    'max_files' => $envInt('HTTP_MAX_FILES', 10),
    'max_file_bytes' => $envInt('HTTP_MAX_FILE_BYTES', 10_000_000),
    'trusted_hosts' => $envList('HTTP_TRUSTED_HOSTS', []),
    'client' => [
        'timeout_seconds' => $envInt('HTTP_CLIENT_TIMEOUT', 8),
        'connect_timeout_seconds' => $envInt('HTTP_CLIENT_CONNECT_TIMEOUT', 3),
        'max_redirects' => $envInt('HTTP_CLIENT_MAX_REDIRECTS', 3),
        'max_bytes' => $envInt('HTTP_CLIENT_MAX_BYTES', 2_000_000),
    ],
    'ssrf' => [
        'allowed_github_hosts' => $envList('HTTP_SSRF_ALLOWED_GITHUB_HOSTS', ['api.github.com', 'github.com']),
        'allowed_s3_host_suffixes' => $envList('HTTP_SSRF_ALLOWED_S3_HOST_SUFFIXES', ['amazonaws.com']),
        'blocked_host_suffixes' => $envList('HTTP_SSRF_BLOCKED_HOST_SUFFIXES', ['localhost', '.local', '.internal']),
    ],
];
