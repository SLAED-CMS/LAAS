<?php

declare(strict_types=1);

namespace Laas\Security;

use Laas\Session\SessionInterface;

final class Csrf
{
    public const SESSION_KEY = '_csrf_token';
    public const FORM_KEY = '_token';
    public const HEADER_KEY = 'X-CSRF-Token';

    public function __construct(private SessionInterface $session)
    {
    }

    public function getToken(): string
    {
        if (!$this->session->isStarted()) {
            return '';
        }

        if (!$this->session->has(self::SESSION_KEY)) {
            $this->session->set(self::SESSION_KEY, $this->generateToken());
        }

        return (string) $this->session->get(self::SESSION_KEY, '');
    }

    public function rotate(): string
    {
        if (!$this->session->isStarted()) {
            return '';
        }

        $this->session->set(self::SESSION_KEY, $this->generateToken());

        return (string) $this->session->get(self::SESSION_KEY, '');
    }

    public function validate(?string $token): bool
    {
        if (!$this->session->isStarted()) {
            return false;
        }

        if ($token === null || $token === '') {
            return false;
        }

        $known = (string) $this->session->get(self::SESSION_KEY, '');
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
