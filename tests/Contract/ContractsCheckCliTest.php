<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class ContractsCheckCliTest extends TestCase
{
    public function testContractsCheckCommandSucceeds(): void
    {
        if (!$this->canShellExec()) {
            $this->markTestSkipped('shell_exec disabled.');
        }

        $root = dirname(__DIR__, 2);
        $cmd = PHP_BINARY . ' ' . escapeshellarg($root . '/tools/cli.php') . ' contracts:check';
        $output = shell_exec($cmd);
        $this->assertIsString($output);
        $this->assertStringContainsString('OK', $output);
    }

    private function canShellExec(): bool
    {
        if (!function_exists('shell_exec')) {
            return false;
        }
        $disabled = (string) ini_get('disable_functions');
        if ($disabled === '') {
            return true;
        }
        $list = array_map('trim', explode(',', $disabled));
        return !in_array('shell_exec', $list, true);
    }
}
