<?php
declare(strict_types=1);

use Laas\Support\PreflightRunner;
use PHPUnit\Framework\TestCase;

final class PreflightCommandTest extends TestCase
{
    public function testPreflightCommand(): void
    {
        if (function_exists('shell_exec')) {
            $root = dirname(__DIR__, 2);
            $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/tools/cli.php') . ' preflight --no-tests --no-db';
            $output = [];
            $code = 0;
            if (function_exists('exec')) {
                exec($cmd, $output, $code);
                $this->assertSame(0, $code, implode("\n", $output));
            } else {
                $result = shell_exec($cmd . ' 2>&1');
                $this->assertNotNull($result);
            }
            return;
        }

        $runner = new PreflightRunner();
        $result = $runner->run([
            ['label' => 'policy:check', 'enabled' => true, 'run' => static fn(): int => 0],
            ['label' => 'contracts:fixtures:check', 'enabled' => true, 'run' => static fn(): int => 0],
            ['label' => 'phpunit', 'enabled' => false, 'run' => static fn(): int => 1],
            ['label' => 'theme:validate', 'enabled' => false, 'run' => static fn(): int => 1],
            ['label' => 'db:check', 'enabled' => false, 'run' => static fn(): int => 1],
        ]);

        $this->assertSame(0, $result['code']);
    }
}
