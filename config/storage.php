<?php
declare(strict_types=1);

$env = $_ENV;
$envString = static function (string $key, string $default) use ($env): string {
    $value = $env[$key] ?? null;
    if ($value === null || $value === '') {
        return $default;
    }
    return (string) $value;
};
$envBool = static function (string $key, bool $default) use ($env): bool {
    $value = $env[$key] ?? null;
    if ($value === null || $value === '') {
        return $default;
    }
    $parsed = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    return $parsed ?? $default;
};
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

$defaultRaw = strtolower(trim($envString('STORAGE_DISK', 'local')));
$defaultDisk = $defaultRaw;
if (!in_array($defaultDisk, ['local', 's3'], true)) {
    $defaultDisk = 'local';
}

$defaultS3Suffixes = $envList('HTTP_SSRF_ALLOWED_S3_HOST_SUFFIXES', ['amazonaws.com']);
$blockedSuffixes = $envList('HTTP_SSRF_BLOCKED_HOST_SUFFIXES', ['localhost', '.local', '.internal']);

return [
    'default_raw' => $defaultRaw,
    'default' => $defaultDisk,
    'disks' => [
        'local' => [
            'root' => 'storage',
        ],
        's3' => [
            'endpoint' => $envString('S3_ENDPOINT', ''),
            'region' => $envString('S3_REGION', ''),
            'bucket' => $envString('S3_BUCKET', ''),
            'access_key' => $envString('S3_ACCESS_KEY', ''),
            'secret_key' => $envString('S3_SECRET_KEY', ''),
            'use_path_style' => $envBool('S3_USE_PATH_STYLE', false),
            'prefix' => trim($envString('S3_PREFIX', ''), '/'),
            'timeout_seconds' => $envInt('S3_TIMEOUT_SECONDS', 10),
            'verify_tls' => $envBool('S3_VERIFY_TLS', true),
            'allowed_host_suffixes' => $envList('S3_ALLOWED_HOST_SUFFIXES', $defaultS3Suffixes),
            'blocked_host_suffixes' => $envList('S3_BLOCKED_HOST_SUFFIXES', $blockedSuffixes),
            'allow_private_ips' => $envBool('S3_ALLOW_PRIVATE_IPS', false),
            'allow_ip_literal' => $envBool('S3_ALLOW_IP_LITERAL', false),
            'allow_http' => $envBool('S3_ALLOW_HTTP', false),
        ],
    ],
];
