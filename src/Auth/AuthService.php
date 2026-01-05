<?php
declare(strict_types=1);

namespace Laas\Auth;

use Laas\Database\Repositories\UsersRepository;
use Laas\Session\SessionInterface;
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

        $regenerated = $this->session->regenerate(true);
        if ($regenerated === false && $this->logger !== null) {
            $this->logger->warning('Session ID regeneration failed', [
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
        $this->session->remove('user_id');
    }

    public function user(): ?array
    {
        $id = (int) $this->session->get('user_id', 0);
        if ($id <= 0) {
            return null;
        }

        return $this->users->findById($id);
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
