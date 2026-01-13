<?php
declare(strict_types=1);

use Laas\Http\Contract\ContractRegistry;
use PHPUnit\Framework\TestCase;

final class ContractsDumpVersionTest extends TestCase
{
    public function testContractsDumpIncludesVersion(): void
    {
        $expectedContractsVersion = '3.18.0';
        $expectedAppVersion = $this->appVersion();
        $root = dirname(__DIR__, 2);
        if ($this->canShellExec()) {
            $cmd = PHP_BINARY . ' ' . escapeshellarg($root . '/tools/cli.php') . ' contracts:dump';
            $output = shell_exec($cmd);
            $this->assertIsString($output);
            $payload = json_decode($output ?? '', true);
            $this->assertIsArray($payload);
            $this->assertSame($expectedContractsVersion, $payload['contracts_version'] ?? null);
            $this->assertSame($expectedAppVersion, $payload['app_version'] ?? null);
            return;
        }

        $this->assertSame($expectedContractsVersion, ContractRegistry::version());
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

    private function appVersion(): string
    {
        $path = dirname(__DIR__, 2) . '/config/app.php';
        $config = is_file($path) ? require $path : [];
        if (!is_array($config)) {
            return '';
        }
        $version = $config['version'] ?? '';
        return is_string($version) ? $version : '';
    }
}
