<?php
declare(strict_types=1);

namespace Laas\Api;

use Laas\Http\Response;

final class ApiResponse
{
    public static function ok(mixed $data, array $meta = [], int $status = 200, array $headers = []): Response
    {
        $payload = [
            'ok' => true,
            'data' => $data,
        ];
        if ($meta !== []) {
            $payload['meta'] = $meta;
        }

        $response = Response::json($payload, $status);
        foreach ($headers as $name => $value) {
            $response = $response->withHeader($name, $value);
        }
        return $response;
    }

    public static function error(string $code, string $message, array $details = [], int $status = 400, array $headers = []): Response
    {
        $payload = [
            'ok' => false,
            'error' => [
                'code' => $code,
                'message' => $message,
            ],
        ];
        if ($details !== []) {
            $payload['error']['details'] = $details;
        }

        $response = Response::json($payload, $status);
        foreach ($headers as $name => $value) {
            $response = $response->withHeader($name, $value);
        }
        return $response;
    }
}
