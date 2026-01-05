<?php
declare(strict_types=1);

namespace Laas\Session;

final class PhpSession implements SessionInterface
{
    public function start(): void
    {
        if ($this->isStarted()) {
            return;
        }

        session_start();
    }

    public function isStarted(): bool
    {
        return session_status() === PHP_SESSION_ACTIVE;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        if (!$this->isStarted()) {
            return $default;
        }

        return $_SESSION[$key] ?? $default;
    }

    public function set(string $key, mixed $value): void
    {
        if (!$this->isStarted()) {
            return;
        }

        $_SESSION[$key] = $value;
    }

    public function has(string $key): bool
    {
        if (!$this->isStarted()) {
            return false;
        }

        return array_key_exists($key, $_SESSION);
    }

    public function remove(string $key): void
    {
        if (!$this->isStarted()) {
            return;
        }

        unset($_SESSION[$key]);
    }

    public function all(): array
    {
        if (!$this->isStarted()) {
            return [];
        }

        return array_merge([], $_SESSION);
    }

    public function clear(): void
    {
        if (!$this->isStarted()) {
            return;
        }

        $_SESSION = [];
    }

    public function regenerate(bool $deleteOldSession = true): bool
    {
        if (!$this->isStarted()) {
            return false;
        }

        return session_regenerate_id($deleteOldSession);
    }
}
