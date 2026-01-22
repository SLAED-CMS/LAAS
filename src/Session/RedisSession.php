<?php

declare(strict_types=1);

namespace Laas\Session;

final class RedisSession extends NativeSession
{
    public function __construct(private RedisSessionHandler $handler)
    {
    }

    public function start(): void
    {
        if ($this->isStarted()) {
            return;
        }

        session_set_save_handler($this->handler, true);
        parent::start();
    }
}
