<?php
declare(strict_types=1);

use Laas\Support\ConfigSanityChecker;
use PHPUnit\Framework\TestCase;

final class MediaConfigValidationTest extends TestCase
{
    public function testInvalidMediaGcConfigIsDetected(): void
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
                'gc_enabled' => 'nope',
                'gc_retention_days' => -1,
                'gc_dry_run_default' => 'nope',
                'gc_max_delete_per_run' => -5,
                'gc_exempt_prefixes' => ['ok', 1],
                'gc_allow_delete_public' => 'nope',
            ],
        ];

        $checker = new ConfigSanityChecker();
        $errors = $checker->check($config);

        $this->assertContains('media.gc_enabled invalid', $errors);
        $this->assertContains('media.gc_retention_days invalid', $errors);
        $this->assertContains('media.gc_dry_run_default invalid', $errors);
        $this->assertContains('media.gc_max_delete_per_run invalid', $errors);
        $this->assertContains('media.gc_exempt_prefixes invalid', $errors);
        $this->assertContains('media.gc_allow_delete_public invalid', $errors);
    }
}
