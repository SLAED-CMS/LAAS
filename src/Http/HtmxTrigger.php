<?php
declare(strict_types=1);

namespace Laas\Http;

final class HtmxTrigger
{
    /**
     * @param array<string, mixed> $payload
     */
    public static function add(Response $response, string $event, array $payload): Response
    {
        $data = self::parseHeader($response->getHeader('HX-Trigger'));
        $data[$event] = $payload;

        $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            return $response;
        }

        return $response->withHeader('HX-Trigger', $json);
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
}
