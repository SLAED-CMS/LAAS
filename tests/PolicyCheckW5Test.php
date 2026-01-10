<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/tools/policy-check.php';

final class PolicyCheckW5Test extends TestCase
{
    public function testInlineStyleAndScriptWarnings(): void
    {
        $root = dirname(__DIR__);
        $fixtures = $root . '/tests/fixtures/policy_w5';

        $analysis = policy_analyze([
            $fixtures . '/warn_style.html',
            $fixtures . '/warn_script.html',
            $fixtures . '/clean_script_src.html',
        ]);

        $warningCodes = array_map(static fn(array $row): string => $row['code'], $analysis['warnings']);

        $this->assertContains('W5', $warningCodes);
    }

    public function testScriptSrcDoesNotTriggerW5(): void
    {
        $root = dirname(__DIR__);
        $fixtures = $root . '/tests/fixtures/policy_w5';

        $analysis = policy_analyze([
            $fixtures . '/clean_script_src.html',
        ]);

        $w5 = array_filter($analysis['warnings'], static fn(array $row): bool => $row['code'] === 'W5');
        $this->assertCount(0, $w5);
    }
}
