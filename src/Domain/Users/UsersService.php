<?php
declare(strict_types=1);

namespace Laas\Domain\Users;

use InvalidArgumentException;
use Laas\Database\DatabaseManager;
use Laas\Database\Repositories\RbacRepository;
use Laas\Database\Repositories\UsersRepository;
use RuntimeException;
use Throwable;

class UsersService
{
    private ?UsersRepository $users = null;
    private ?RbacRepository $rbac = null;

    public function __construct(private DatabaseManager $db)
    {
    }

    /** @return array<int, array<string, mixed>> */
    public function list(array $filters = []): array
    {
        $limit = (int) ($filters['limit'] ?? 100);
        $offset = (int) ($filters['offset'] ?? 0);
        if ($limit <= 0) {
            throw new InvalidArgumentException('Limit must be positive.');
        }
        if ($offset < 0) {
            throw new InvalidArgumentException('Offset must be zero or positive.');
        }

        $query = trim((string) ($filters['query'] ?? ''));
        $repo = $this->usersRepository();
        $rows = $query !== ''
            ? $repo->search($query, $limit, $offset)
            : $repo->list($limit, $offset);

        return array_map([$this, 'normalizeUser'], $rows);
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        if ($id <= 0) {
            throw new InvalidArgumentException('User id must be positive.');
        }

        $row = $this->usersRepository()->findById($id);
        return $row === null ? null : $this->normalizeUser($row);
    }

    public function count(array $filters = []): int
    {
        $query = trim((string) ($filters['query'] ?? ''));
        $repo = $this->usersRepository();
        return $query !== '' ? $repo->countSearch($query) : $repo->countAll();
    }

    /** @return array<int, array<int, string>> */
    public function rolesForUsers(array $userIds): array
    {
        return $this->rbacRepository()->getRolesForUsers($userIds);
    }

    /** @return array<int, string> */
    public function rolesForUser(int $userId): array
    {
        if ($userId <= 0) {
            throw new InvalidArgumentException('User id must be positive.');
        }

        return $this->rbacRepository()->listUserRoles($userId);
    }

    public function isAdmin(int $userId): bool
    {
        if ($userId <= 0) {
            throw new InvalidArgumentException('User id must be positive.');
        }

        return $this->rbacRepository()->userHasRole($userId, 'admin');
    }

    public function setStatus(int $userId, int $status): void
    {
        $status = $status === 1 ? 1 : 0;
        $this->usersRepository()->setStatus($userId, $status);
    }

    public function setPasswordHash(int $userId, string $hash): void
    {
        $this->usersRepository()->setPasswordHash($userId, $hash);
    }

    public function setAdminRole(int $userId, bool $isAdmin): void
    {
        $rbac = $this->rbacRepository();
        if ($isAdmin) {
            $rbac->grantRoleToUser($userId, 'admin');
            return;
        }

        $rbac->revokeRoleFromUser($userId, 'admin');
    }

    public function delete(int $userId): void
    {
        $pdo = $this->db->pdo();
        $stmt = $pdo->prepare('DELETE FROM role_user WHERE user_id = :user_id');
        $stmt->execute(['user_id' => $userId]);
        $this->usersRepository()->delete($userId);
    }

    private function usersRepository(): UsersRepository
    {
        if ($this->users !== null) {
            return $this->users;
        }

        if (!$this->db->healthCheck()) {
            throw new RuntimeException('Database unavailable.');
        }

        try {
            $this->users = new UsersRepository($this->db->pdo());
        } catch (Throwable $e) {
            throw new RuntimeException('Database unavailable.', 0, $e);
        }

        return $this->users;
    }

    private function rbacRepository(): RbacRepository
    {
        if ($this->rbac !== null) {
            return $this->rbac;
        }

        if (!$this->db->healthCheck()) {
            throw new RuntimeException('Database unavailable.');
        }

        try {
            $this->rbac = new RbacRepository($this->db->pdo());
        } catch (Throwable $e) {
            throw new RuntimeException('Database unavailable.', 0, $e);
        }

        return $this->rbac;
    }

    /** @return array<string, mixed> */
    private function normalizeUser(array $user): array
    {
        $status = (int) ($user['status'] ?? 0);
        $user['status'] = $status;
        $user['active'] = $status === 1;

        return $user;
    }
}
