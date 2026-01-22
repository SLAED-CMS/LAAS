<?php

declare(strict_types=1);

namespace Laas\Database\Repositories;

use PDO;

final class PasswordResetRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    public function createToken(string $email, string $hashedToken, int $expiresInSeconds = 3600): void
    {
        $driver = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

        if ($driver === 'mysql') {
            $sql = 'INSERT INTO password_reset_tokens (email, token, expires_at)
                    VALUES (:email, :token, DATE_ADD(NOW(), INTERVAL :seconds SECOND))';
        } else {
            $sql = 'INSERT INTO password_reset_tokens (email, token, expires_at)
                    VALUES (:email, :token, datetime(\'now\', \'+\' || :seconds || \' seconds\'))';
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            'email' => $email,
            'token' => $hashedToken,
            'seconds' => $expiresInSeconds,
        ]);
    }

    public function findByToken(string $hashedToken): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM password_reset_tokens WHERE token = :token LIMIT 1'
        );
        $stmt->execute(['token' => $hashedToken]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    public function isValid(array $tokenRecord): bool
    {
        $expiresAt = (string) ($tokenRecord['expires_at'] ?? '');
        if ($expiresAt === '') {
            return false;
        }

        $now = date('Y-m-d H:i:s');
        return $expiresAt > $now;
    }

    public function deleteToken(string $hashedToken): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM password_reset_tokens WHERE token = :token');
        $stmt->execute(['token' => $hashedToken]);
    }

    public function deleteByEmail(string $email): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM password_reset_tokens WHERE email = :email');
        $stmt->execute(['email' => $email]);
    }

    public function cleanupExpired(): int
    {
        $stmt = $this->pdo->prepare('DELETE FROM password_reset_tokens WHERE expires_at < NOW()');
        $stmt->execute();
        return $stmt->rowCount();
    }
}
