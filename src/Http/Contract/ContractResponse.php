<?php
declare(strict_types=1);

namespace Laas\Http\Contract;

use Laas\Http\ErrorResponse;
use Laas\Http\Response;
use Laas\Http\ResponseMeta;
use Laas\Support\RequestScope;

final class ContractResponse
{
    public static function ok(array $data, array $meta = [], int $status = 200): Response
    {
        $meta = self::normalizeMeta($meta);

        return Response::json([
            'data' => $data,
            'meta' => $meta,
        ], $status);
    }

    public static function error(string $error, array $meta = [], int $status = 400, array $fields = []): Response
    {
        $meta = self::normalizeMeta($meta);
        $details = $fields !== [] ? ['fields' => $fields] : [];
        $request = RequestScope::getRequest();
        $built = ErrorResponse::buildPayload($request, $error, $details, $status, $meta);

        return Response::json($built['payload'], $built['status']);
    }

    private static function normalizeMeta(array $meta): array
    {
        $meta['format'] = 'json';
        return ResponseMeta::enrich($meta);
    }
}
