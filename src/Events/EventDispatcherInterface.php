<?php

declare(strict_types=1);

namespace Laas\Events;

interface EventDispatcherInterface
{
    public function addListener(string $event, callable $listener, int $priority = 0): void;

    public function dispatch(object $event): object;
}
