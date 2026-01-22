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
    private int $ttlSettings;

    public function __construct(private PDO $pdo, ?CacheInterface $cache = null)
    {
        if ($cache !== null) {
            $this->cache = $cache;
        } else {
            $rootPath = dirname(__DIR__, 3);
            $this->cache = CacheFactory::create($rootPath);
        }
        $this->invalidator = new SettingsCacheInvalidator($this->cache);
        $rootPath = dirname(__DIR__, 3);
        $config = CacheFactory::config($rootPath);
        $this->ttlSettings = (int) ($config['ttl_settings'] ?? $config['ttl_default'] ?? 60);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $all = $this->cache->get(CacheKey::settingsAll());
        if (is_array($all) && isset($all['values']) && is_array($all['values'])) {
            if (array_key_exists($key, $all['values'])) {
                return $all['values'][$key];
            }
        }

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
            $this->cache->set(CacheKey::settingsKey($key), ['__missing__' => true], $this->ttlSettings);
            return $default;
        }

        $value = $this->deserialize((string) $row['value'], (string) $row['type']);
        $this->cache->set(CacheKey::settingsKey($key), $value, $this->ttlSettings);
        return $value;
    }

    public function has(string $key): bool
    {
        if ($this->shouldLog()) {
            error_log("[SettingsRepo] has({$key}) START");
        }

        $all = $this->cache->get(CacheKey::settingsAll());
        if ($this->shouldLog()) {
            error_log("[SettingsRepo] has({$key}) - settingsAll() cache: " . ($all === null ? 'NULL' : 'EXISTS'));
        }
        if (is_array($all) && isset($all['values']) && is_array($all['values'])) {
            $result = array_key_exists($key, $all['values']);
            if ($this->shouldLog()) {
                error_log("[SettingsRepo] has({$key}) - found in settingsAll: " . ($result ? 'YES' : 'NO'));
            }
            if ($result) {
                return true;
            }
        }

        $cached = $this->cache->get(CacheKey::settingsKey($key));
        if ($this->shouldLog()) {
            error_log("[SettingsRepo] has({$key}) - individual cache: " . json_encode($cached));
        }
        if (is_array($cached) && isset($cached['__missing__'])) {
            if ($this->shouldLog()) {
                error_log("[SettingsRepo] has({$key}) - __missing__ marker found, returning FALSE");
            }
            return false;
        }
        if ($cached !== null) {
            if ($this->shouldLog()) {
                error_log("[SettingsRepo] has({$key}) - cached value exists, returning TRUE");
            }
            return true;
        }

        if ($this->shouldLog()) {
            error_log("[SettingsRepo] has({$key}) - checking DB");
        }
        $stmt = $this->pdo->prepare('SELECT 1 FROM settings WHERE `key` = :key LIMIT 1');
        $stmt->execute(['key' => $key]);
        $exists = (bool) $stmt->fetchColumn();
        if ($this->shouldLog()) {
            error_log("[SettingsRepo] has({$key}) - DB result: " . ($exists ? 'EXISTS' : 'MISSING'));
        }
        if (!$exists) {
            if ($this->shouldLog()) {
                error_log("[SettingsRepo] has({$key}) - setting __missing__ marker in cache");
            }
            $this->cache->set(CacheKey::settingsKey($key), ['__missing__' => true], $this->ttlSettings);
        }
        return $exists;
    }

    public function set(string $key, mixed $value, string $type = 'string'): void
    {
        if ($this->shouldLog()) {
            error_log("[SettingsRepo] set({$key}) START - value: " . json_encode($value) . ", type: {$type}");
        }
        $stored = $this->serialize($value, $type);
        if ($this->shouldLog()) {
            error_log("[SettingsRepo] set({$key}) - serialized: {$stored}");
        }
        $this->persistSetting($key, $stored, $type);
        if ($this->shouldLog()) {
            error_log("[SettingsRepo] set({$key}) - DB write OK");
        }

        if ($this->shouldLog()) {
            error_log("[SettingsRepo] set({$key}) - calling invalidateKey()");
        }
        $this->invalidator->invalidateKey($key);
        if ($this->shouldLog()) {
            error_log("[SettingsRepo] set({$key}) - DONE");
        }
    }

    public function setWithoutInvalidation(string $key, mixed $value, string $type = 'string'): void
    {
        if ($this->shouldLog()) {
            error_log("[SettingsRepo] setWithoutInvalidation({$key}) - value: " . json_encode($value) . ", type: {$type}");
        }
        $stored = $this->serialize($value, $type);
        $this->persistSetting($key, $stored, $type);
        if ($this->shouldLog()) {
            error_log("[SettingsRepo] setWithoutInvalidation({$key}) - DB write OK (no cache invalidation)");
        }
    }

    public function invalidateSettings(): void
    {
        if ($this->shouldLog()) {
            error_log('[SettingsRepo] invalidateSettings() - clearing ALL settings cache');
        }
        $this->cache->delete(CacheKey::settingsAll());
        if ($this->shouldLog()) {
            error_log('[SettingsRepo] invalidateSettings() - DONE');
        }
    }

    /** @return array<string, mixed> */
    public function getAll(): array
    {
        if ($this->shouldLog()) {
            error_log('[SettingsRepo] getAll() START');
        }
        $cached = $this->cache->get(CacheKey::settingsAll());
        if ($this->shouldLog()) {
            error_log('[SettingsRepo] getAll() - cache: ' . ($cached === null ? 'NULL' : 'EXISTS'));
        }
        if (is_array($cached) && isset($cached['values']) && is_array($cached['values'])) {
            if ($this->shouldLog()) {
                error_log('[SettingsRepo] getAll() - returning cached values, keys: ' . implode(', ', array_keys($cached['values'])));
            }
            return $cached['values'];
        }

        if ($this->shouldLog()) {
            error_log('[SettingsRepo] getAll() - cache miss, loading from DB');
        }
        $stmt = $this->pdo->query('SELECT `key`, `value`, `type` FROM settings');
        $rows = $stmt !== false ? $stmt->fetchAll() : [];
        $values = [];
        foreach ($rows as $row) {
            $key = (string) ($row['key'] ?? '');
            if ($key === '') {
                continue;
            }
            $values[$key] = $this->deserialize((string) ($row['value'] ?? ''), (string) ($row['type'] ?? 'string'));
        }

        if ($this->shouldLog()) {
            error_log('[SettingsRepo] getAll() - loaded from DB, keys: ' . implode(', ', array_keys($values)));
        }

        $sources = [];
        foreach (array_keys($values) as $key) {
            $sources[$key] = 'DB';
        }

        if ($this->shouldLog()) {
            error_log('[SettingsRepo] getAll() - setting cache');
        }
        $this->cache->set(CacheKey::settingsAll(), [
            'values' => $values,
            'sources' => $sources,
        ], $this->ttlSettings);

        return $values;
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

    private function shouldLog(): bool
    {
        $env = strtolower((string) getenv('APP_ENV'));
        if ($env === 'test') {
            return false;
        }
        if (getenv('CI') === 'true') {
            return false;
        }
        return true;
    }

    private function persistSetting(string $key, string $stored, string $type): void
    {
        $driver = (string) $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'sqlite') {
            $now = date('Y-m-d H:i:s');
            $stmt = $this->pdo->prepare('SELECT 1 FROM settings WHERE `key` = :key');
            $stmt->execute(['key' => $key]);
            $exists = (bool) $stmt->fetchColumn();
            if ($exists) {
                $stmt = $this->pdo->prepare(
                    'UPDATE settings SET `value` = :value, `type` = :type, `updated_at` = :updated_at WHERE `key` = :key'
                );
                $stmt->execute([
                    'key' => $key,
                    'value' => $stored,
                    'type' => $type,
                    'updated_at' => $now,
                ]);
                return;
            }

            $stmt = $this->pdo->prepare(
                'INSERT INTO settings (`key`, `value`, `type`, `updated_at`) VALUES (:key, :value, :type, :updated_at)'
            );
            $stmt->execute([
                'key' => $key,
                'value' => $stored,
                'type' => $type,
                'updated_at' => $now,
            ]);
            return;
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO settings (`key`, `value`, `type`, `updated_at`) VALUES (:key, :value, :type, NOW())
             ON DUPLICATE KEY UPDATE `value` = VALUES(`value`), `type` = VALUES(`type`), `updated_at` = NOW()'
        );
        $stmt->execute([
            'key' => $key,
            'value' => $stored,
            'type' => $type,
        ]);
    }
}
