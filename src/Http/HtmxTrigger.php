<?php

declare(strict_types=1);

namespace Laas\Http;

final class HtmxTrigger
{
    public const EVENT_TOAST = 'laas:toast';

    /**
     * @param array<string, mixed> $toast
     */
    public static function addToast(Response $response, array $toast): Response
    {
        return self::add($response, self::EVENT_TOAST, $toast);
    }

    /**
     * @param array<string, mixed> $toast
     */
    public static function toToastHeader(array $toast): string
    {
        $data = [self::EVENT_TOAST => $toast];
        $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return $json === false ? '{}' : $json;
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function add(Response $response, string $event, array $payload): Response
    {
        $headerName = self::resolveHeaderName($response);
        $data = self::parseHeader($response->getHeader($headerName));
        $data[$event] = $payload;

        $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            return $response;
        }

        return $response->withHeader($headerName, $json);
    }

    /**
     * @return array<string, mixed>
     */
    private static function parseHeader(?string $header): array
    {
        if ($header === null || $header === '') {
            return [];
        }

        $decoded = json_decode($header, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        return [$header => true];
    }

    private static function resolveHeaderName(Response $response): string
    {
        return $response->getStatus() === 303 ? 'HX-Trigger-After-Settle' : 'HX-Trigger';
    }
}
