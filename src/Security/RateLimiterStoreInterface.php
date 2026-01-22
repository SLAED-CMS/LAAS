<?php

declare(strict_types=1);

namespace Laas\Security;

interface RateLimiterStoreInterface
{
    /** @return array<string, mixed>|null */
    public function get(string $key): ?array;

    /** @param array<string, mixed> $state */
    public function set(string $key, array $state, int $ttlSeconds): bool;

    public function delete(string $key): bool;
}
