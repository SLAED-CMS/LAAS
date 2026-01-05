<?php
declare(strict_types=1);

namespace Laas\Session;

interface SessionInterface
{
    public function start(): void;
    public function isStarted(): bool;
    public function get(string $key, mixed $default = null): mixed;
    public function set(string $key, mixed $value): void;
    public function has(string $key): bool;
    public function remove(string $key): void;
    /** @return array<string, mixed> */
    public function all(): array;
    public function clear(): void;
    public function regenerate(bool $deleteOldSession = true): bool;
}
