<?php
declare(strict_types=1);

namespace Laas\Database\Repositories;

use Laas\Settings\SettingsCacheInvalidator;
use Laas\Support\Cache\CacheFactory;
use Laas\Support\Cache\CacheInterface;
use Laas\Support\Cache\CacheKey;
use PDO;

final class SettingsRepository
{
    private CacheInterface $cache;
    private SettingsCacheInvalidator $invalidator;

    public function __construct(private PDO $pdo, ?CacheInterface $cache = null)
    {
        if ($cache !== null) {
            $this->cache = $cache;
        } else {
            $rootPath = dirname(__DIR__, 3);
            $this->cache = CacheFactory::create($rootPath);
        }
        $this->invalidator = new SettingsCacheInvalidator($this->cache);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $cached = $this->cache->get(CacheKey::settingsKey($key));
        if (is_array($cached) && isset($cached['__missing__'])) {
            return $default;
        }
        if ($cached !== null) {
            return $cached;
        }

        $stmt = $this->pdo->prepare('SELECT value, type FROM settings WHERE `key` = :key');
        $stmt->execute(['key' => $key]);
        $row = $stmt->fetch();
        if (!$row) {
            $this->cache->set(CacheKey::settingsKey($key), ['__missing__' => true]);
            return $default;
        }

        $value = $this->deserialize((string) $row['value'], (string) $row['type']);
        $this->cache->set(CacheKey::settingsKey($key), $value);
        return $value;
    }

    public function has(string $key): bool
    {
        $cached = $this->cache->get(CacheKey::settingsKey($key));
        if (is_array($cached) && isset($cached['__missing__'])) {
            return false;
        }
        if ($cached !== null) {
            return true;
        }

        $stmt = $this->pdo->prepare('SELECT 1 FROM settings WHERE `key` = :key LIMIT 1');
        $stmt->execute(['key' => $key]);
        $exists = (bool) $stmt->fetchColumn();
        if (!$exists) {
            $this->cache->set(CacheKey::settingsKey($key), ['__missing__' => true]);
        }
        return $exists;
    }

    public function set(string $key, mixed $value, string $type = 'string'): void
    {
        $stored = $this->serialize($value, $type);
        $stmt = $this->pdo->prepare(
            'INSERT INTO settings (`key`, `value`, `type`, `updated_at`) VALUES (:key, :value, :type, NOW())
             ON DUPLICATE KEY UPDATE `value` = VALUES(`value`), `type` = VALUES(`type`), `updated_at` = NOW()'
        );
        $stmt->execute([
            'key' => $key,
            'value' => $stored,
            'type' => $type,
        ]);

        $this->invalidator->invalidateKey($key);
    }

    private function serialize(mixed $value, string $type): string
    {
        return match ($type) {
            'bool' => $value ? '1' : '0',
            'int' => (string) ((int) $value),
            'json' => json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}',
            default => (string) $value,
        };
    }

    private function deserialize(string $value, string $type): mixed
    {
        return match ($type) {
            'bool' => $value === '1',
            'int' => (int) $value,
            'json' => json_decode($value, true),
            default => $value,
        };
    }
}
