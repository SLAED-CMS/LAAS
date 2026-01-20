<?php
declare(strict_types=1);

namespace Laas\Domain\Users;

interface UsersWriteServiceInterface
{
    /** @mutation */
    public function updateLoginMeta(int $userId, string $ip): void;

    /** @mutation */
    public function setTotpSecret(int $userId, ?string $secret): void;

    /** @mutation */
    public function setTotpEnabled(int $userId, bool $enabled): void;

    /** @mutation */
    public function setBackupCodes(int $userId, ?string $codes): void;

    /** @mutation */
    public function setStatus(int $userId, int $status): void;

    /** @mutation */
    public function setPasswordHash(int $userId, string $hash): void;

    /** @mutation */
    public function setAdminRole(int $userId, bool $isAdmin): void;

    /** @mutation */
    public function createPasswordResetToken(string $email, string $hashedToken, int $expiresInSeconds = 3600): void;

    /** @mutation */
    public function deletePasswordResetToken(string $hashedToken): void;

    /** @mutation */
    public function deletePasswordResetByEmail(string $email): void;

    /** @mutation */
    public function delete(int $userId): void;
}
