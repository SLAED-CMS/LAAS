<?php
declare(strict_types=1);

namespace Laas\Http\Contract;

use Laas\DevTools\DevToolsContext;
use Laas\Http\Response;
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
        $payload = [
            'error' => $error,
            'meta' => $meta,
        ];
        if ($fields !== []) {
            $payload['fields'] = $fields;
        }

        return Response::json($payload, $status);
    }

    private static function normalizeMeta(array $meta): array
    {
        $meta['format'] = 'json';
        $requestId = self::resolveRequestId();
        if ($requestId !== null) {
            $meta['request_id'] = $requestId;
        }

        return $meta;
    }

    private static function resolveRequestId(): ?string
    {
        $context = RequestScope::get('devtools.context');
        if ($context instanceof DevToolsContext) {
            $id = $context->getRequestId();
            return $id !== '' ? $id : null;
        }

        return null;
    }
}
