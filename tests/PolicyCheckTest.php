<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/tools/policy-check.php';

final class PolicyCheckTest extends TestCase
{
    public function testPolicyCheckFindsInlineAndCdnViolations(): void
    {
        $root = dirname(__DIR__);
        $fixtures = $root . '/tests/fixtures/policy';

        $analysis = policy_analyze([$fixtures]);
        $codes = array_map(static fn(array $row): string => $row['code'], $analysis['errors']);

        $this->assertContains('R1', $codes);
        $this->assertContains('R2', $codes);
    }
}
