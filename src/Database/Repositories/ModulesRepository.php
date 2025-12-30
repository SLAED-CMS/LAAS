<?php
declare(strict_types=1);

namespace Laas\Database\Repositories;

use PDO;

final class ModulesRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    /** @return array<string, array{enabled: bool, version: string|null, installed_at: string|null, updated_at: string}> */
    public function all(): array
    {
        $stmt = $this->pdo->query('SELECT name, enabled, version, installed_at, updated_at FROM modules');
        $rows = $stmt ? $stmt->fetchAll() : [];

        $result = [];
        foreach ($rows as $row) {
            $name = (string) $row['name'];
            $result[$name] = [
                'enabled' => (bool) $row['enabled'],
                'version' => $row['version'] !== null ? (string) $row['version'] : null,
                'installed_at' => $row['installed_at'] !== null ? (string) $row['installed_at'] : null,
                'updated_at' => (string) $row['updated_at'],
            ];
        }

        return $result;
    }

    public function isEnabled(string $name): bool
    {
        $stmt = $this->pdo->prepare('SELECT enabled FROM modules WHERE name = :name');
        $stmt->execute(['name' => $name]);
        $row = $stmt->fetch();
        if (!$row) {
            return false;
        }

        return (bool) $row['enabled'];
    }

    public function enable(string $name): void
    {
        $this->upsert($name, true, null);
    }

    public function disable(string $name): void
    {
        $this->upsert($name, false, null);
    }

    public function upsert(string $name, bool $enabled, ?string $version = null): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO modules (`name`, `enabled`, `version`, `installed_at`, `updated_at`)
             VALUES (:name, :enabled, :version, NOW(), NOW())
             ON DUPLICATE KEY UPDATE `enabled` = VALUES(`enabled`), `version` = VALUES(`version`), `updated_at` = NOW()'
        );
        $stmt->execute([
            'name' => $name,
            'enabled' => $enabled ? 1 : 0,
            'version' => $version,
        ]);
    }

    /** @return array<string, array{enabled: bool, version: string|null, installed_at: string|null, updated_at: string}> */
    public function sync(array $discovered, array $configEnabled): array
    {
        $current = $this->all();

        foreach ($discovered as $name => $meta) {
            if (!isset($current[$name])) {
                $enabled = in_array($name, $configEnabled, true);
                $this->upsert($name, $enabled, $meta['version'] ?? null);
                $current[$name] = [
                    'enabled' => $enabled,
                    'version' => $meta['version'] ?? null,
                    'installed_at' => null,
                    'updated_at' => '',
                ];
            }
        }

        return $this->all();
    }
}
