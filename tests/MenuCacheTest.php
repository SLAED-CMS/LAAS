<?php
declare(strict_types=1);

use Laas\Modules\Menu\Service\MenuCacheInvalidator;
use Laas\Support\Cache\CacheKey;
use Laas\Support\Cache\FileCache;
use PHPUnit\Framework\TestCase;

final class MenuCacheTest extends TestCase
{
    public function testMenuCacheKeyedByLocale(): void
    {
        $dir = sys_get_temp_dir() . '/laas_cache_' . bin2hex(random_bytes(4));
        @mkdir($dir, 0775, true);
        $cache = new FileCache($dir, 'laas', 300);

        $cache->set(CacheKey::menu('main', 'en'), ['id' => 1]);
        $cache->set(CacheKey::menu('main', 'ru'), ['id' => 2]);

        $this->assertSame(['id' => 1], $cache->get(CacheKey::menu('main', 'en')));
        $this->assertSame(['id' => 2], $cache->get(CacheKey::menu('main', 'ru')));
    }

    public function testMenuInvalidationOnChange(): void
    {
        $dir = sys_get_temp_dir() . '/laas_cache_' . bin2hex(random_bytes(4));
        @mkdir($dir, 0775, true);
        $cache = new FileCache($dir, 'laas', 300);
        $invalidator = new MenuCacheInvalidator($cache, ['en', 'ru']);

        $cache->set(CacheKey::menu('main', 'en'), ['id' => 1]);
        $cache->set(CacheKey::menu('main', 'ru'), ['id' => 2]);

        $invalidator->invalidate('main');

        $this->assertNull($cache->get(CacheKey::menu('main', 'en')));
        $this->assertNull($cache->get(CacheKey::menu('main', 'ru')));
    }
}
