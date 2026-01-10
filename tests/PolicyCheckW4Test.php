<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/tools/policy-check.php';

final class PolicyCheckW4Test extends TestCase
{
    public function testCdnWarningIsReported(): void
    {
        $root = dirname(__DIR__);
        $fixtures = $root . '/tests/fixtures/policy_w4';

        $analysis = policy_analyze([
            $fixtures . '/warn_cdn.html',
            $fixtures . '/clean.html',
        ]);

        $warningCodes = array_map(static fn(array $row): string => $row['code'], $analysis['warnings']);

        $this->assertContains('W4', $warningCodes);
        $this->assertCount(0, $analysis['errors']);
    }
}
