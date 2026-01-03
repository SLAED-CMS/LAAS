<?php
declare(strict_types=1);

use Laas\Database\DatabaseManager;
use Laas\Settings\SettingsCacheInvalidator;
use Laas\Settings\SettingsProvider;
use Laas\Support\Cache\CacheFactory;
use Laas\Support\Cache\CacheKey;
use Laas\Support\Cache\FileCache;
use PHPUnit\Framework\TestCase;

final class SettingsCacheTest extends TestCase
{
    public function testSettingsCacheHitSkipsDb(): void
    {
        $root = dirname(__DIR__);
        $cache = CacheFactory::create($root);
        $cache->delete(CacheKey::settingsAll());
        $cache->delete(CacheKey::settingsKey('site_name'));

        $db = new DatabaseManager(['driver' => 'sqlite', 'database' => ':memory:']);
        $pdo = $db->pdo();
        $pdo->exec('CREATE TABLE settings (`key` TEXT PRIMARY KEY, value TEXT, type TEXT, updated_at TEXT)');
        $pdo->exec("INSERT INTO settings (`key`, `value`, `type`, `updated_at`) VALUES ('site_name','My Site','string','2026-01-01 00:00:00')");

        $provider = new SettingsProvider($db, ['site_name' => 'Default'], ['site_name']);
        $this->assertSame('My Site', $provider->get('site_name'));

        $emptyDb = new DatabaseManager(['driver' => 'sqlite', 'database' => ':memory:']);
        $cachedProvider = new SettingsProvider($emptyDb, ['site_name' => 'Default'], ['site_name']);
        $this->assertSame('My Site', $cachedProvider->get('site_name'));
    }

    public function testSettingsInvalidationOnUpdate(): void
    {
        $dir = sys_get_temp_dir() . '/laas_cache_' . bin2hex(random_bytes(4));
        @mkdir($dir, 0775, true);
        $cache = new FileCache($dir, 'laas', 300);
        $invalidator = new SettingsCacheInvalidator($cache);

        $cache->set(CacheKey::settingsAll(), ['values' => ['site_name' => 'X']]);
        $cache->set(CacheKey::settingsKey('site_name'), 'X');

        $invalidator->invalidateKey('site_name');

        $this->assertNull($cache->get(CacheKey::settingsAll()));
        $this->assertNull($cache->get(CacheKey::settingsKey('site_name')));
    }
}
