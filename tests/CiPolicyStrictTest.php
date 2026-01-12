<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/tools/policy-check.php';

final class CiPolicyStrictTest extends TestCase
{
    public function testCoreThemeWarningsBecomeErrorsInStrictMode(): void
    {
        $root = dirname(__DIR__);
        $fixtures = $root . '/tests/fixtures/policy_core';

        $backup = $_ENV;
        try {
            $_ENV['POLICY_CORE_THEME_STRICT'] = 'true';
            $analysis = policy_analyze([$fixtures]);
        } finally {
            $_ENV = $backup;
        }

        $errorCodes = array_map(static fn(array $row): string => $row['code'], $analysis['errors']);
        $warningCodes = array_map(static fn(array $row): string => $row['code'], $analysis['warnings']);

        $this->assertContains('W5', $errorCodes);
        $this->assertContains('W6', $errorCodes);
        $this->assertContains('W5', $warningCodes);
    }
}
