<?php
declare(strict_types=1);

namespace Laas\Http;

use Laas\Database\DbProfileCollector;
use Laas\DevTools\DevToolsContext;
use Laas\Support\RequestScope;

final class ResponseMeta
{
    public static function enrich(array $meta): array
    {
        $meta['request_id'] = RequestContext::requestId();
        $meta['ts'] = RequestContext::timestamp();

        $debug = false;
        $context = RequestScope::get('devtools.context');
        if ($context instanceof DevToolsContext) {
            $debug = (bool) $context->getFlag('debug', false);
        }
        if ($debug) {
            $collector = RequestScope::get('db.profile');
            if ($collector instanceof DbProfileCollector) {
                $perf = $meta['perf'] ?? [];
                if (!is_array($perf)) {
                    $perf = [];
                }
                $perf['db'] = $collector->toArray();
                $meta['perf'] = $perf;
            }
        }

        return $meta;
    }
}
