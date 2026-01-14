<?php
declare(strict_types=1);

namespace Laas\Http;

use Laas\Support\RequestScope;

final class UiEventRegistry
{
    private const KEY = 'ui.toast.events';

    /**
     * @param array<string, mixed> $event
     */
    public static function pushEvent(array $event): void
    {
        $events = self::currentEvents();
        $events[] = $event;
        RequestScope::set(self::KEY, $events);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function consumeEvents(): array
    {
        $events = self::currentEvents();
        RequestScope::set(self::KEY, []);
        return array_values($events);
    }

    public static function clear(): void
    {
        RequestScope::set(self::KEY, []);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function currentEvents(): array
    {
        $value = RequestScope::get(self::KEY);
        if (!is_array($value)) {
            return [];
        }

        $events = [];
        foreach ($value as $item) {
            if (is_array($item)) {
                $events[] = $item;
            }
        }

        return array_values($events);
    }
}
