<?php
declare(strict_types=1);

namespace Laas\Security;

final class Csrf
{
    public const SESSION_KEY = '_csrf_token';
    public const FORM_KEY = '_token';
    public const HEADER_KEY = 'X-CSRF-Token';

    public function getToken(): string
    {
        if (!isset($_SESSION[self::SESSION_KEY])) {
            $_SESSION[self::SESSION_KEY] = $this->generateToken();
        }

        return (string) $_SESSION[self::SESSION_KEY];
    }

    public function rotate(): string
    {
        $_SESSION[self::SESSION_KEY] = $this->generateToken();

        return (string) $_SESSION[self::SESSION_KEY];
    }

    public function validate(?string $token): bool
    {
        if ($token === null || $token === '') {
            return false;
        }

        $known = $_SESSION[self::SESSION_KEY] ?? '';
        if ($known === '') {
            return false;
        }

        return hash_equals((string) $known, $token);
    }

    private function generateToken(): string
    {
        $bytes = random_bytes(32);
        $token = rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');

        return $token;
    }
}
