<?php
declare(strict_types=1);

use Laas\Http\Contract\ContractDump;
use Laas\Http\Contract\ContractFixtureNormalizer;
use PHPUnit\Framework\TestCase;

final class ContractSnapshotStabilityTest extends TestCase
{
    public function testContractsDumpMatchesSnapshot(): void
    {
        $snapshotPath = dirname(__DIR__) . '/fixtures/contracts/_snapshot.json';
        $this->assertFileExists($snapshotPath);

        $raw = (string) file_get_contents($snapshotPath);
        $expected = json_decode($raw, true);
        $this->assertIsArray($expected, 'Invalid snapshot JSON.');

        $appVersion = $this->appVersion();
        $dump = ContractDump::build($appVersion);
        $normalized = ContractFixtureNormalizer::normalize($dump);

        $this->assertSame($expected, $normalized, 'Contracts snapshot mismatch. Run contracts:snapshot:update.');
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
