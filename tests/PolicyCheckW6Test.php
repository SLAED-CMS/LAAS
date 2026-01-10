<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/tools/policy-check.php';

final class PolicyCheckW6Test extends TestCase
{
    public function testMissingCanonicalLayoutProducesWarning(): void
    {
        $root = dirname(__DIR__);
        $fixtures = $root . '/tests/fixtures/policy_w6';

        $analysis = policy_analyze([$fixtures]);

        $w6 = array_filter($analysis['warnings'], static fn(array $row): bool => $row['code'] === 'W6');
        $this->assertGreaterThan(0, count($w6));
    }

    public function testCanonicalLayoutPresentHasNoWarning(): void
    {
        $root = dirname(__DIR__);
        $fixtures = $root . '/tests/fixtures/policy_w6_ok';

        $analysis = policy_analyze([$fixtures]);

        $w6 = array_filter($analysis['warnings'], static fn(array $row): bool => $row['code'] === 'W6');
        $this->assertCount(0, $w6);
    }
}
