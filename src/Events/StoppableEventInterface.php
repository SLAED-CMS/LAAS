<?php

declare(strict_types=1);

namespace Laas\Events;

interface StoppableEventInterface
{
    public function isPropagationStopped(): bool;
}
