<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/tools/policy-check.php';

final class PolicyCheckW3Test extends TestCase
{
    public function testW3aDetectedAndStrictExitCode(): void
    {
        $root = dirname(__DIR__);
        $fixtureDir = $root . '/tests/fixtures_w3';

        $prevStrict = $_ENV['POLICY_STRICT'] ?? null;
        $prevExclude = $_ENV['POLICY_W3_EXCLUDE'] ?? null;

        $_ENV['POLICY_W3_EXCLUDE'] = '';

        $_ENV['POLICY_STRICT'] = 'false';
        $analysis = policy_analyze([$fixtureDir]);
        $w3a = $this->countWarnings($analysis, 'W3a');
        $this->assertGreaterThan(0, $w3a);
        $this->assertSame(0, policy_exit_code($analysis));

        $_ENV['POLICY_STRICT'] = 'true';
        $analysisStrict = policy_analyze([$fixtureDir]);
        $this->assertSame(1, policy_exit_code($analysisStrict));

        if ($prevStrict === null) {
            unset($_ENV['POLICY_STRICT']);
        } else {
            $_ENV['POLICY_STRICT'] = $prevStrict;
        }
        if ($prevExclude === null) {
            unset($_ENV['POLICY_W3_EXCLUDE']);
        } else {
            $_ENV['POLICY_W3_EXCLUDE'] = $prevExclude;
        }
    }

    private function countWarnings(array $analysis, string $code): int
    {
        $count = 0;
        foreach ($analysis['warnings'] as $warning) {
            if (($warning['code'] ?? '') === $code) {
                $count++;
            }
        }
        return $count;
    }
}
