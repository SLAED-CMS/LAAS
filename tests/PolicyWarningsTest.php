<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/tools/policy-check.php';

final class PolicyWarningsTest extends TestCase
{
    public function testWarningsDoNotFailExitCode(): void
    {
        $root = dirname(__DIR__);
        $fixtures = $root . '/tests/fixtures/policy';

        $analysis = policy_analyze([
            $fixtures . '/warn_onclick.html',
            $fixtures . '/warn_style.html',
            $fixtures . '/clean.html',
        ]);

        $warningCodes = array_map(static fn(array $row): string => $row['code'], $analysis['warnings']);

        $this->assertContains('W1', $warningCodes);
        $this->assertContains('W2', $warningCodes);
        $this->assertCount(0, $analysis['errors']);
        $this->assertSame(0, policy_exit_code($analysis));
    }
}
