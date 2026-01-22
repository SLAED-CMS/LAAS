<?php
declare(strict_types=1);

namespace Tests\Cache;

use Laas\Support\Cache\FileCache;
use PHPUnit\Framework\TestCase;

final class FileCacheTest extends TestCase
{
    private string $cacheDir;

    protected function setUp(): void
    {
        $this->cacheDir = sys_get_temp_dir() . '/laas_test_cache_' . bin2hex(random_bytes(4));
        mkdir($this->cacheDir, 0775, true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->cacheDir)) {
            $files = glob($this->cacheDir . '/*');
            foreach ($files as $file) {
                @unlink($file);
            }
            @rmdir($this->cacheDir);
        }
    }

    public function testSetGetRoundTrip(): void
    {
        $cache = new FileCache($this->cacheDir, 'test', 300, false);
        $this->assertTrue($cache->set('alpha', ['a' => 1]));
        $this->assertSame(['a' => 1], $cache->get('alpha'));
    }

    public function testDefaultOnMiss(): void
    {
        $cache = new FileCache($this->cacheDir, 'test', 300, false);
        $this->assertSame('fallback', $cache->get('missing', 'fallback'));
    }

    public function testHasAndDelete(): void
    {
        $cache = new FileCache($this->cacheDir, 'test', 300, false);
        $cache->set('k1', 'v1');
        $this->assertTrue($cache->has('k1'));
        $this->assertTrue($cache->delete('k1'));
        $this->assertFalse($cache->has('k1'));
    }

    public function testTtlExpires(): void
    {
        $cache = new FileCache($this->cacheDir, 'test', 300, false);
        $cache->set('short', 'ttl', 1);
        sleep(2);
        $this->assertSame('gone', $cache->get('short', 'gone'));
        $this->assertFalse($cache->has('short'));
    }

    public function testClearRemovesEntries(): void
    {
        $cache = new FileCache($this->cacheDir, 'test', 300, false);
        $cache->set('a', 1);
        $cache->set('b', 2);
        $this->assertTrue($cache->clear());
        $this->assertFalse($cache->has('a'));
        $this->assertFalse($cache->has('b'));
    }
}
