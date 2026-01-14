<?php
declare(strict_types=1);

use Laas\Http\Contract\ContractRegistry;
use PHPUnit\Framework\TestCase;

final class ContractsAndFixturesCoverageTest extends TestCase
{
    public function testErrorContractsHaveFixtures(): void
    {
        $required = [
            'error.invalid_request',
            'error.auth_required',
            'error.rbac_denied',
            'error.not_found',
            'http.payload_too_large',
            'http.uri_too_long',
            'http.headers_too_large',
            'rate_limited',
            'service_unavailable',
        ];

        $contracts = ContractRegistry::all();
        $names = [];
        foreach ($contracts as $spec) {
            $name = is_string($spec['name'] ?? null) ? $spec['name'] : '';
            if ($name !== '') {
                $names[] = $name;
            }
        }

        $dir = dirname(__DIR__) . '/fixtures/contracts';
        foreach ($required as $name) {
            $this->assertContains($name, $names, 'Missing contract: ' . $name);
            $this->assertFileExists($dir . '/' . $name . '.json', 'Missing fixture: ' . $name);
        }
    }
}
