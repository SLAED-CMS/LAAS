<?php
declare(strict_types=1);

namespace Laas\Database\Repositories;

use PDO;
use Laas\Support\Search\LikeEscaper;

final class UsersRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    public function findByUsername(string $username): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM users WHERE username = :username LIMIT 1');
        $stmt->execute(['username' => $username]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    public function findByEmail(string $email): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM users WHERE email = :email LIMIT 1');
        $stmt->execute(['email' => $email]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM users WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    public function updateLoginMeta(int $id, string $ip): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE users SET last_login_at = NOW(), last_login_ip = :ip, updated_at = NOW() WHERE id = :id'
        );
        $stmt->execute([
            'id' => $id,
            'ip' => $ip,
        ]);
    }

    /** @return array<int, array<string, mixed>> */
    public function list(int $limit = 50, int $offset = 0): array
    {
        $limit = max(1, min(200, $limit));
        $offset = max(0, $offset);

        $stmt = $this->pdo->prepare(
            'SELECT id, username, email, status, last_login_at, last_login_ip, created_at FROM users ORDER BY id ASC LIMIT :limit OFFSET :offset'
        );
        $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue('offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        $rows = $stmt->fetchAll();
        return is_array($rows) ? $rows : [];
    }

    /** @return array<int, array<string, mixed>> */
    public function search(string $query, int $limit, int $offset): array
    {
        $query = trim($query);
        if ($query === '') {
            return [];
        }

        $escaped = LikeEscaper::escape($query);
        $prefix = $escaped . '%';
        $contains = '%' . $escaped . '%';

        $sql = 'SELECT id, username, email, status, last_login_at, last_login_ip, created_at FROM users';
        $sql .= ' WHERE (username LIKE :contains ESCAPE \'\\\' OR email LIKE :contains ESCAPE \'\\\')';
        $sql .= ' ORDER BY CASE WHEN username LIKE :prefix ESCAPE \'\\\' OR email LIKE :prefix ESCAPE \'\\\' THEN 0 ELSE 1 END, id DESC';
        $sql .= ' LIMIT :limit OFFSET :offset';

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue('limit', max(1, min(50, $limit)), PDO::PARAM_INT);
        $stmt->bindValue('offset', max(0, $offset), PDO::PARAM_INT);
        $stmt->bindValue('contains', $contains);
        $stmt->bindValue('prefix', $prefix);
        $stmt->execute();

        $rows = $stmt->fetchAll();
        return is_array($rows) ? $rows : [];
    }

    public function countSearch(string $query): int
    {
        $query = trim($query);
        if ($query === '') {
            return 0;
        }

        $escaped = LikeEscaper::escape($query);
        $contains = '%' . $escaped . '%';
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) AS cnt FROM users WHERE (username LIKE :contains ESCAPE \'\\\' OR email LIKE :contains ESCAPE \'\\\')'
        );
        $stmt->execute(['contains' => $contains]);
        $row = $stmt->fetch();

        return (int) ($row['cnt'] ?? 0);
    }

    public function countAll(): int
    {
        $stmt = $this->pdo->query('SELECT COUNT(*) AS cnt FROM users');
        $row = $stmt->fetch();

        return (int) ($row['cnt'] ?? 0);
    }

    public function setStatus(int $id, int $status): void
    {
        $stmt = $this->pdo->prepare('UPDATE users SET status = :status, updated_at = NOW() WHERE id = :id');
        $stmt->execute([
            'id' => $id,
            'status' => $status,
        ]);
    }

    public function setPasswordHash(int $id, string $hash): void
    {
        $stmt = $this->pdo->prepare('UPDATE users SET password_hash = :hash, updated_at = NOW() WHERE id = :id');
        $stmt->execute([
            'id' => $id,
            'hash' => $hash,
        ]);
    }

    public function delete(int $id): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM users WHERE id = :id');
        $stmt->execute([
            'id' => $id,
        ]);
    }

    public function setTotpSecret(int $id, ?string $secret): void
    {
        $stmt = $this->pdo->prepare('UPDATE users SET totp_secret = :secret, updated_at = NOW() WHERE id = :id');
        $stmt->execute([
            'id' => $id,
            'secret' => $secret,
        ]);
    }

    public function setTotpEnabled(int $id, bool $enabled): void
    {
        $stmt = $this->pdo->prepare('UPDATE users SET totp_enabled = :enabled, updated_at = NOW() WHERE id = :id');
        $stmt->execute([
            'id' => $id,
            'enabled' => $enabled ? 1 : 0,
        ]);
    }

    public function setBackupCodes(int $id, ?string $codes): void
    {
        $stmt = $this->pdo->prepare('UPDATE users SET backup_codes = :codes, updated_at = NOW() WHERE id = :id');
        $stmt->execute([
            'id' => $id,
            'codes' => $codes,
        ]);
    }

    public function getTotpData(int $id): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT totp_secret, totp_enabled, backup_codes FROM users WHERE id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return $row ?: null;
    }
}
