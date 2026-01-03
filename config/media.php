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
$envJson = static function (string $key, array $default) use ($env): array {
    $value = $env[$key] ?? null;
    if ($value === null || $value === '') {
        return $default;
    }
    $decoded = json_decode((string) $value, true);
    return is_array($decoded) ? $decoded : $default;
};

$allowed = $envString('MEDIA_ALLOWED_MIME', 'image/jpeg,image/png,image/webp,application/pdf');
$allowedList = array_filter(array_map('trim', explode(',', $allowed)));
$maxBytesByMime = $envJson('MEDIA_MAX_BYTES_BY_MIME', []);
$maxBytesByMimeFiltered = [];
foreach ($maxBytesByMime as $mime => $limit) {
    if (!is_string($mime) || $mime === '' || !is_numeric($limit)) {
        continue;
    }
    $limit = (int) $limit;
    if ($limit <= 0) {
        continue;
    }
    $maxBytesByMimeFiltered[$mime] = $limit;
}

return [
    'max_bytes' => $envInt('MEDIA_MAX_BYTES', 10 * 1024 * 1024),
    'public' => $envBool('MEDIA_PUBLIC', false),
    'allowed_mime' => $allowedList,
    'max_bytes_by_mime' => $maxBytesByMimeFiltered,
    'av_enabled' => $envBool('MEDIA_AV_ENABLED', false),
    'av_socket' => $envString('MEDIA_AV_SOCKET', '/var/run/clamav/clamd.ctl'),
    'av_timeout' => $envInt('MEDIA_AV_TIMEOUT', 8),
];
