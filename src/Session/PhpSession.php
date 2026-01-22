<?php

declare(strict_types=1);

namespace Laas\Session;

final class PhpSession implements SessionInterface
{
    private NativeSession $inner;

    public function __construct(?NativeSession $inner = null)
    {
        // Use composition to avoid extending final NativeSession while preserving behavior.
        $this->inner = $inner ?? new NativeSession();
    }

    public function start(): void
    {
        $this->inner->start();
    }

    public function isStarted(): bool
    {
        return $this->inner->isStarted();
    }

    public function regenerateId(bool $deleteOld = true): void
    {
        $this->inner->regenerateId($deleteOld);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->inner->get($key, $default);
    }

    public function set(string $key, mixed $value): void
    {
        $this->inner->set($key, $value);
    }

    public function has(string $key): bool
    {
        return $this->inner->has($key);
    }

    public function delete(string $key): void
    {
        $this->inner->delete($key);
    }

    public function all(): array
    {
        return $this->inner->all();
    }

    public function clear(): void
    {
        $this->inner->clear();
    }
}
