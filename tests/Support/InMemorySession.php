<?php
declare(strict_types=1);

namespace Tests\Support;

use Laas\Session\SessionInterface;

final class InMemorySession implements SessionInterface
{
    private bool $started = false;
    private array $data = [];
    public int $regenerateCalls = 0;

    public function start(): void
    {
        $this->started = true;
    }

    public function isStarted(): bool
    {
        return $this->started;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        if (!$this->started) {
            return $default;
        }

        return $this->data[$key] ?? $default;
    }

    public function set(string $key, mixed $value): void
    {
        if (!$this->started) {
            return;
        }

        $this->data[$key] = $value;
    }

    public function has(string $key): bool
    {
        if (!$this->started) {
            return false;
        }

        return array_key_exists($key, $this->data);
    }

    public function remove(string $key): void
    {
        if (!$this->started) {
            return;
        }

        unset($this->data[$key]);
    }

    public function all(): array
    {
        if (!$this->started) {
            return [];
        }

        return array_merge([], $this->data);
    }

    public function clear(): void
    {
        if (!$this->started) {
            return;
        }

        $this->data = [];
    }

    public function regenerate(bool $deleteOldSession = true): bool
    {
        if (!$this->started) {
            return false;
        }

        $this->regenerateCalls++;
        return true;
    }
}
