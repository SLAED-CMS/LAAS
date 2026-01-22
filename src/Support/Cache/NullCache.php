<?php

declare(strict_types=1);

namespace Laas\Support\Cache;

final class NullCache implements CacheInterface
{
    public function get(string $key): mixed
    {
        return null;
    }

    public function set(string $key, mixed $value, ?int $ttl = null): bool
    {
        return true;
    }

    public function delete(string $key): bool
    {
        return true;
    }
}
