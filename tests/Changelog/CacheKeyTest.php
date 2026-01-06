<?php
declare(strict_types=1);

use Laas\Modules\Changelog\Support\ChangelogCache;
use PHPUnit\Framework\TestCase;

final class CacheKeyTest extends TestCase
{
    public function testKeyIncludesParts(): void
    {
        $root = dirname(__DIR__, 2);
        $cache = new ChangelogCache($root);
        $key = $cache->buildKey('github', 'main', 2, 20, true);

        $this->assertSame('changelog:v1:github:main:2:20:1', $key);
    }
}
