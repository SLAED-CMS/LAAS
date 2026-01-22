<?php

declare(strict_types=1);

namespace Laas\Http;

final class UiToast
{
    public const EVENT_NAME = 'laas:toast';

    private const TYPES = [
        'success',
        'info',
        'warning',
        'danger',
    ];

    public static function payload(
        string $type,
        string $message,
        ?string $title = null,
        ?string $code = null,
        ?int $ttlMs = null,
        ?string $dedupeKey = null
    ): array {
        if (!in_array($type, self::TYPES, true)) {
            $type = 'info';
        }

        $message = self::sanitizeText($message);
        $title = $title !== null ? self::sanitizeText($title) : null;

        $payload = [
            'type' => $type,
            'message' => $message,
            'request_id' => RequestContext::requestId(),
        ];

        if ($title !== null && $title !== '') {
            $payload['title'] = $title;
        }

        if ($code !== null && $code !== '') {
            $payload['code'] = $code;
        }

        if ($ttlMs !== null) {
            $payload['ttl_ms'] = $ttlMs;
        }

        if ($dedupeKey !== null && $dedupeKey !== '') {
            $payload['dedupe_key'] = $dedupeKey;
        }

        return $payload;
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function register(array $payload): void
    {
        UiEventRegistry::pushEvent($payload);
    }

    public static function success(
        string $message,
        ?string $code = null,
        ?int $ttlMs = null,
        ?string $title = null,
        ?string $dedupeKey = null
    ): array {
        if ($ttlMs === null) {
            $ttlMs = 4000;
        }

        return self::payload('success', $message, $title, $code, $ttlMs, $dedupeKey);
    }

    public static function info(
        string $message,
        ?string $code = null,
        ?int $ttlMs = null,
        ?string $title = null,
        ?string $dedupeKey = null
    ): array {
        return self::payload('info', $message, $title, $code, $ttlMs, $dedupeKey);
    }

    public static function warning(
        string $message,
        ?string $code = null,
        ?int $ttlMs = null,
        ?string $title = null,
        ?string $dedupeKey = null
    ): array {
        return self::payload('warning', $message, $title, $code, $ttlMs, $dedupeKey);
    }

    public static function danger(
        string $message,
        ?string $code = null,
        ?int $ttlMs = null,
        ?string $title = null,
        ?string $dedupeKey = null
    ): array {
        return self::payload('danger', $message, $title, $code, $ttlMs, $dedupeKey);
    }

    public static function registerSuccess(
        string $message,
        ?string $code = null,
        ?int $ttlMs = null,
        ?string $title = null,
        ?string $dedupeKey = null
    ): array {
        $payload = self::success($message, $code, $ttlMs, $title, $dedupeKey);
        self::register($payload);
        return $payload;
    }

    public static function registerInfo(
        string $message,
        ?string $code = null,
        ?int $ttlMs = null,
        ?string $title = null,
        ?string $dedupeKey = null
    ): array {
        $payload = self::info($message, $code, $ttlMs, $title, $dedupeKey);
        self::register($payload);
        return $payload;
    }

    public static function registerWarning(
        string $message,
        ?string $code = null,
        ?int $ttlMs = null,
        ?string $title = null,
        ?string $dedupeKey = null
    ): array {
        $payload = self::warning($message, $code, $ttlMs, $title, $dedupeKey);
        self::register($payload);
        return $payload;
    }

    public static function registerDanger(
        string $message,
        ?string $code = null,
        ?int $ttlMs = null,
        ?string $title = null,
        ?string $dedupeKey = null
    ): array {
        $payload = self::danger($message, $code, $ttlMs, $title, $dedupeKey);
        self::register($payload);
        return $payload;
    }

    private static function sanitizeText(string $value): string
    {
        $value = strip_tags($value);
        $value = str_replace(['<', '>'], '', $value);
        return trim($value);
    }
}
