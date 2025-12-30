<?php
declare(strict_types=1);

namespace Laas\Auth;

final class NullAuthService implements AuthInterface
{
    public function attempt(string $username, string $password, string $ip): bool
    {
        return false;
    }

    public function logout(): void
    {
    }

    public function user(): ?array
    {
        return null;
    }

    public function check(): bool
    {
        return false;
    }
}
