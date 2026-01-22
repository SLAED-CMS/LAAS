<?php

declare(strict_types=1);

namespace Laas\Security;

interface ClockInterface
{
    public function now(): int;
}
