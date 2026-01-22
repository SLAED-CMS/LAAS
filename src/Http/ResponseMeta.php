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

        return self::appendEvents($meta);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function normalizeEvents(mixed $events): array
    {
        if (!is_array($events)) {
            return [];
        }

        $out = [];
        foreach ($events as $event) {
            if (is_array($event)) {
                $out[] = $event;
            }
        }

        return array_values($out);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function collectEvents(array $meta): array
    {
        $existing = self::normalizeEvents($meta['events'] ?? []);
        $additional = self::normalizeEvents(UiEventRegistry::consumeEvents());
        if ($existing === [] && $additional === []) {
            return [];
        }

        return array_merge($existing, $additional);
    }

    private static function appendEvents(array $meta): array
    {
        $events = self::collectEvents($meta);
        if ($events === []) {
            return $meta;
        }

        if (count($events) > 3) {
            $events = array_slice($events, 0, 3);
        }

        $meta['events'] = $events;
        return $meta;
    }
}
