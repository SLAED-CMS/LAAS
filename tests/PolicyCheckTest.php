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

        $violations = policy_check_paths([$fixtures]);

        $rules = array_map(static fn(array $row): string => $row['rule'], $violations);

        $this->assertContains('R1', $rules);
        $this->assertContains('R2', $rules);
    }
}
