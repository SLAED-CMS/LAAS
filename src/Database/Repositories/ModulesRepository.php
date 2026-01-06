<?php
declare(strict_types=1);

namespace Laas\Database\Repositories;

use PDO;
use Laas\Support\RequestScope;

final class ModulesRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    /** @return array<string, array{enabled: bool, version: string|null, installed_at: string|null, updated_at: string}> */
    public function all(): array
    {
        if (RequestScope::has('modules.list')) {
            $cached = RequestScope::get('modules.list');
            if (is_array($cached)) {
                return $cached;
            }
        }

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

        RequestScope::set('modules.list', $result);
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
        RequestScope::forget('modules.list');
        $this->upsert($name, true, null);
    }

    public function disable(string $name): void
    {
        RequestScope::forget('modules.list');
        $this->upsert($name, false, null);
    }

    public function upsert(string $name, bool $enabled, ?string $version = null): void
    {
        RequestScope::forget('modules.list');
        $driver = (string) $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'sqlite') {
            $now = date('Y-m-d H:i:s');
            $stmt = $this->pdo->prepare('SELECT name FROM modules WHERE name = :name');
            $stmt->execute(['name' => $name]);
            $exists = (bool) $stmt->fetch();
            if ($exists) {
                $stmt = $this->pdo->prepare('UPDATE modules SET enabled = :enabled, version = :version, updated_at = :updated_at WHERE name = :name');
                $stmt->execute([
                    'name' => $name,
                    'enabled' => $enabled ? 1 : 0,
                    'version' => $version,
                    'updated_at' => $now,
                ]);
                return;
            }

            $stmt = $this->pdo->prepare(
                'INSERT INTO modules (`name`, `enabled`, `version`, `installed_at`, `updated_at`)
                 VALUES (:name, :enabled, :version, :installed_at, :updated_at)'
            );
            $stmt->execute([
                'name' => $name,
                'enabled' => $enabled ? 1 : 0,
                'version' => $version,
                'installed_at' => $now,
                'updated_at' => $now,
            ]);
            return;
        }

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
        RequestScope::forget('modules.list');
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

        RequestScope::forget('modules.list');
        return $this->all();
    }
}
