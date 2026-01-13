<?php
declare(strict_types=1);

use Laas\Http\Contract\ContractRegistry;
use PHPUnit\Framework\TestCase;

final class FixturesMustHaveContractsTest extends TestCase
{
    public function testFixturesMapToContracts(): void
    {
        $contracts = ContractRegistry::all();
        $names = [];
        foreach ($contracts as $spec) {
            $name = is_string($spec['name'] ?? null) ? $spec['name'] : '';
            if ($name !== '') {
                $names[] = $name;
            }
        }

        $dir = dirname(__DIR__) . '/fixtures/contracts';
        $files = glob($dir . '/*.json') ?: [];
        $files = array_values(array_filter($files, static function (string $file): bool {
            return basename($file) !== '_snapshot.json';
        }));

        foreach ($files as $file) {
            $fixtureName = basename($file, '.json');
            $this->assertNotNull($this->matchContract($fixtureName, $names), 'Unknown fixture contract: ' . $fixtureName);
        }
    }

    /** @param array<int, string> $names */
    private function matchContract(string $fixtureName, array $names): ?string
    {
        $match = null;
        $maxLen = -1;
        foreach ($names as $name) {
            if ($fixtureName === $name || str_starts_with($fixtureName, $name . '.')) {
                $len = strlen($name);
                if ($len > $maxLen) {
                    $maxLen = $len;
                    $match = $name;
                }
            }
        }

        return $match;
    }
}
