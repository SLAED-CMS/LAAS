<?php

declare(strict_types=1);

namespace Laas\Auth;

interface AuthInterface
{
    public function attempt(string $username, string $password, string $ip): bool;

    public function logout(): void;

    public function user(): ?array;

    public function check(): bool;
}
