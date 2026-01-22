<?php

declare(strict_types=1);

namespace Laas\Events;

final class SimpleEventDispatcher implements EventDispatcherInterface
{
    /** @var array<string, array<int, array{priority: int, index: int, listener: callable}>> */
    private array $listeners = [];
    private int $index = 0;

    public function addListener(string $event, callable $listener, int $priority = 0): void
    {
        $this->listeners[$event][] = [
            'priority' => $priority,
            'index' => $this->index++,
            'listener' => $listener,
        ];
    }

    public function dispatch(object $event): object
    {
        $eventName = $event::class;
        $listeners = $this->listeners[$eventName] ?? [];
        if ($listeners === []) {
            return $event;
        }

        usort($listeners, static function (array $a, array $b): int {
            if ($a['priority'] === $b['priority']) {
                return $a['index'] <=> $b['index'];
            }
            return $b['priority'] <=> $a['priority'];
        });

        foreach ($listeners as $entry) {
            $listener = $entry['listener'];
            $listener($event);

            if ($event instanceof StoppableEventInterface && $event->isPropagationStopped()) {
                break;
            }
        }

        return $event;
    }
}
