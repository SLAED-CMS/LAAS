<?php

declare(strict_types=1);

namespace Laas\Api;

use Laas\Http\ErrorResponse;
use Laas\Http\Response;
use Laas\Http\ResponseMeta;
use Laas\Support\RequestScope;

final class ApiResponse
{
    public static function ok(mixed $data, array $meta = [], int $status = 200, array $headers = []): Response
    {
        $meta = ResponseMeta::enrich($meta);
        $payload = [
            'ok' => true,
            'data' => $data,
            'meta' => $meta,
        ];

        $response = Response::json($payload, $status);
        foreach ($headers as $name => $value) {
            $response = $response->withHeader($name, $value);
        }
        return $response;
    }

    public static function error(string $code, string $message, array $details = [], int $status = 400, array $headers = []): Response
    {
        $request = RequestScope::getRequest();
        $built = ErrorResponse::buildPayload($request, $code, $details, $status);
        $response = Response::json($built['payload'], $built['status']);
        foreach ($headers as $name => $value) {
            $response = $response->withHeader($name, $value);
        }
        return $response;
    }
}
