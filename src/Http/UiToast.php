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

    /**
     * @param array<string, mixed>|null $context
     */
    public static function payload(
        string $type,
        string $messageKey,
        string $message,
        ?array $context = null,
        ?int $ttlMs = null
    ): array {
        if (!in_array($type, self::TYPES, true)) {
            $type = 'info';
        }

        $payload = [
            'type' => $type,
            'message_key' => $messageKey,
            'message' => $message !== '' ? $message : null,
            'request_id' => RequestContext::requestId(),
        ];

        if ($context !== null && $context !== []) {
            $payload['context'] = $context;
        }

        if ($ttlMs !== null) {
            $payload['ttl_ms'] = $ttlMs;
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

    /**
     * @param array<string, mixed>|null $context
     */
    public static function success(
        string $messageKey,
        string $message,
        ?array $context = null,
        ?int $ttlMs = null
    ): array {
        return self::payload('success', $messageKey, $message, $context, $ttlMs);
    }

    /**
     * @param array<string, mixed>|null $context
     */
    public static function info(
        string $messageKey,
        string $message,
        ?array $context = null,
        ?int $ttlMs = null
    ): array {
        return self::payload('info', $messageKey, $message, $context, $ttlMs);
    }

    /**
     * @param array<string, mixed>|null $context
     */
    public static function warning(
        string $messageKey,
        string $message,
        ?array $context = null,
        ?int $ttlMs = null
    ): array {
        return self::payload('warning', $messageKey, $message, $context, $ttlMs);
    }

    /**
     * @param array<string, mixed>|null $context
     */
    public static function danger(
        string $messageKey,
        string $message,
        ?array $context = null,
        ?int $ttlMs = null
    ): array {
        return self::payload('danger', $messageKey, $message, $context, $ttlMs);
    }

    /**
     * @param array<string, mixed>|null $context
     */
    public static function registerSuccess(
        string $messageKey,
        string $message,
        ?array $context = null,
        ?int $ttlMs = null
    ): array {
        $payload = self::success($messageKey, $message, $context, $ttlMs);
        self::register($payload);
        return $payload;
    }

    /**
     * @param array<string, mixed>|null $context
     */
    public static function registerInfo(
        string $messageKey,
        string $message,
        ?array $context = null,
        ?int $ttlMs = null
    ): array {
        $payload = self::info($messageKey, $message, $context, $ttlMs);
        self::register($payload);
        return $payload;
    }

    /**
     * @param array<string, mixed>|null $context
     */
    public static function registerWarning(
        string $messageKey,
        string $message,
        ?array $context = null,
        ?int $ttlMs = null
    ): array {
        $payload = self::warning($messageKey, $message, $context, $ttlMs);
        self::register($payload);
        return $payload;
    }

    /**
     * @param array<string, mixed>|null $context
     */
    public static function registerDanger(
        string $messageKey,
        string $message,
        ?array $context = null,
        ?int $ttlMs = null
    ): array {
        $payload = self::danger($messageKey, $message, $context, $ttlMs);
        self::register($payload);
        return $payload;
    }
}
