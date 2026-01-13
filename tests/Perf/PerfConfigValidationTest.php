<?php
declare(strict_types=1);

use Laas\Support\ConfigSanityChecker;
use PHPUnit\Framework\TestCase;

final class PerfConfigValidationTest extends TestCase
{
    public function testInvalidPerfAndCacheConfigIsDetected(): void
    {
        $config = [
            'storage' => [
                'default_raw' => 'local',
                'default' => 'local',
                'disks' => ['s3' => []],
            ],
            'media' => [
                'max_bytes' => 10,
                'allowed_mime' => ['image/png'],
            ],
            'perf' => [
                'guard_mode' => 'invalid',
                'db_max_queries' => -1,
                'total_max_ms' => 'nope',
                'guard_exempt_paths' => ['/health', 123],
            ],
            'cache' => [
                'ttl_default' => -5,
                'devtools_tracking' => 'nope',
            ],
        ];

        $checker = new ConfigSanityChecker();
        $errors = $checker->check($config);

        $this->assertContains('perf.guard_mode invalid', $errors);
        $this->assertContains('perf.db_max_queries invalid', $errors);
        $this->assertContains('perf.total_max_ms invalid', $errors);
        $this->assertContains('perf.guard_exempt_paths invalid', $errors);
        $this->assertContains('cache.ttl_default invalid', $errors);
        $this->assertContains('cache.devtools_tracking invalid', $errors);
    }
}
