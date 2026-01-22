<?php

declare(strict_types=1);

namespace Laas\Auth;

use Laas\Database\Repositories\UsersRepository;
use Laas\Session\SessionInterface;
use Laas\Support\RequestScope;
use Psr\Log\LoggerInterface;

final class AuthService implements AuthInterface
{
    public function __construct(
        private UsersRepository $users,
        private SessionInterface $session,
        private ?LoggerInterface $logger = null
    ) {
    }

    public function attempt(string $username, string $password, string $ip): bool
    {
        $user = $this->users->findByUsername($username);
        if ($user === null || (int) ($user['status'] ?? 0) !== 1) {
            $this->logFailed($username, $ip);
            return false;
        }

        $hash = (string) ($user['password_hash'] ?? '');
        if (!password_verify($password, $hash)) {
            $this->logFailed($username, $ip);
            return false;
        }

        if ($this->session->isStarted()) {
            $this->session->regenerateId(true);
            $now = time();
            $this->session->set('_session_started_at', $now);
            $this->session->set('_last_activity', $now);
        } elseif ($this->logger !== null) {
            $this->logger->warning('Session ID regeneration skipped', [
                'username' => $username,
                'ip' => $ip,
            ]);
        }

        $this->session->set('user_id', (int) $user['id']);
        $this->users->updateLoginMeta((int) $user['id'], $ip);

        return true;
    }

    public function logout(): void
    {
        $this->session->delete('user_id');
    }

    public function user(): ?array
    {
        if (RequestScope::has('auth.current_user')) {
            $cached = RequestScope::get('auth.current_user');
            return is_array($cached) ? $cached : null;
        }

        $id = (int) $this->session->get('user_id', 0);
        if ($id <= 0) {
            RequestScope::set('auth.current_user', false);
            return null;
        }

        $user = $this->users->findById($id);
        RequestScope::set('auth.current_user', $user ?? false);
        return $user;
    }

    public function check(): bool
    {
        return $this->user() !== null;
    }

    private function logFailed(string $username, string $ip): void
    {
        if ($this->logger === null) {
            return;
        }

        $this->logger->warning('Auth failed', [
            'username' => $username,
            'ip' => $ip,
        ]);
    }
}
