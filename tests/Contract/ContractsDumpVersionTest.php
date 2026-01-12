<?php
declare(strict_types=1);

use Laas\Http\Contract\ContractRegistry;
use PHPUnit\Framework\TestCase;

final class ContractsDumpVersionTest extends TestCase
{
    public function testContractsDumpIncludesVersion(): void
    {
        $root = dirname(__DIR__, 2);
        if ($this->canShellExec()) {
            $cmd = PHP_BINARY . ' ' . escapeshellarg($root . '/tools/cli.php') . ' contracts:dump';
            $output = shell_exec($cmd);
            $this->assertIsString($output);
            $payload = json_decode($output ?? '', true);
            $this->assertIsArray($payload);
            $this->assertSame('1.0', $payload['contracts_version'] ?? null);
            return;
        }

        $this->assertSame('1.0', ContractRegistry::version());
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
