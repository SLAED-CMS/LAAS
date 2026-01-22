<?php
declare(strict_types=1);

use Laas\Security\CacheRateLimiterStore;
use Laas\Security\ClockInterface;
use Laas\Security\RateLimiter;
use Laas\Support\Cache\FileCache;
use PHPUnit\Framework\TestCase;

final class RateLimiterCacheTest extends TestCase
{
    private string $rootPath;
    private string $cacheDir;

    protected function setUp(): void
    {
        $this->rootPath = sys_get_temp_dir() . '/laas-rate-test-' . bin2hex(random_bytes(4));
        $this->cacheDir = $this->rootPath . '/storage/cache/data';
        mkdir($this->cacheDir, 0775, true);
        mkdir($this->rootPath . '/storage/cache/ratelimit', 0775, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->rootPath);
    }

    public function testAllowsWithinWindowAndBlocksOverflow(): void
    {
        $clock = new FakeClock(1000);
        $limiter = $this->createLimiter($clock);

        $first = $limiter->hit('login', '127.0.0.1', 60, 2);
        $second = $limiter->hit('login', '127.0.0.1', 60, 2);
        $third = $limiter->hit('login', '127.0.0.1', 60, 2);

        $this->assertTrue($first['allowed']);
        $this->assertTrue($second['allowed']);
        $this->assertFalse($third['allowed']);
    }

    public function testAllowsAfterWindowReset(): void
    {
        $clock = new FakeClock(2000);
        $limiter = $this->createLimiter($clock);

        $limiter->hit('login', '127.0.0.1', 60, 1);
        $blocked = $limiter->hit('login', '127.0.0.1', 60, 1);
        $this->assertFalse($blocked['allowed']);

        $clock->advance(61);

        $allowed = $limiter->hit('login', '127.0.0.1', 60, 1);
        $this->assertTrue($allowed['allowed']);
    }

    private function createLimiter(FakeClock $clock): RateLimiter
    {
        $cache = new FileCache($this->cacheDir, 'test', 300, false);
        $store = new CacheRateLimiterStore($cache);
        return new RateLimiter($this->rootPath, $store, $clock);
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($items as $item) {
            if ($item->isDir()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }

        @rmdir($dir);
    }
}

final class FakeClock implements ClockInterface
{
    private int $now;

    public function __construct(int $now)
    {
        $this->now = $now;
    }

    public function now(): int
    {
        return $this->now;
    }

    public function advance(int $seconds): void
    {
        $this->now += $seconds;
    }
}
