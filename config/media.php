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

$allowed = $envString('MEDIA_ALLOWED_MIME', 'image/jpeg,image/png,image/gif,image/webp,application/pdf');
$allowedList = array_filter(array_map('trim', explode(',', $allowed)));

return [
    'max_bytes' => $envInt('MEDIA_MAX_BYTES', 10 * 1024 * 1024),
    'public' => $envBool('MEDIA_PUBLIC', false),
    'allowed_mime' => $allowedList,
];
