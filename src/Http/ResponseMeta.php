<?php
declare(strict_types=1);

namespace Laas\Http;

final class ResponseMeta
{
    public static function enrich(array $meta): array
    {
        $meta['request_id'] = RequestContext::requestId();
        $meta['ts'] = RequestContext::timestamp();

        return $meta;
    }
}
