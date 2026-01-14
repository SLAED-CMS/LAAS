<?php
declare(strict_types=1);

use Laas\Http\Contract\ContractFixtureNormalizer;
use Laas\Http\Contract\ContractRegistry;
use PHPUnit\Framework\TestCase;

final class ContractFixturesSmokeTest extends TestCase
{
    public function testFixturesMatchRegistryExamples(): void
    {
        $required = [
            'pages.show',
            'api.auth.failed',
            'api.auth.forbidden_scope',
            'rbac.forbidden',
            'auth.unauthorized',
            'http.bad_request',
            'http.not_found',
            'security.csrf_failed',
            'http.rate_limited',
            'api.validation_failed',
            'system.read_only',
            'service_unavailable',
            'admin.login.validation_failed',
            'admin.modules.index',
            'admin.settings.save.validation_failed',
            'admin.pages.save.validation_failed',
            'admin.api_tokens.index',
            'admin.api_tokens.create',
            'admin.api_tokens.create.validation_failed',
            'admin.api_tokens.revoke',
            'admin.users.index',
            'admin.media.index',
            'media.show',
        ];

        $dir = dirname(__DIR__) . '/fixtures/contracts';
        $fixtures = $this->buildFixtureMap(ContractRegistry::all());

        foreach ($required as $fixtureName) {
            $this->assertArrayHasKey($fixtureName, $fixtures, 'Missing registry example: ' . $fixtureName);
            $path = $dir . '/' . $fixtureName . '.json';
            $this->assertFileExists($path);
            $raw = (string) file_get_contents($path);
            $expected = json_decode($raw, true);
            $this->assertIsArray($expected, 'Invalid fixture JSON: ' . $fixtureName);

            $payload = ContractFixtureNormalizer::normalize($fixtures[$fixtureName]['payload']);
            $this->assertSame($expected, $payload, 'Fixture mismatch: ' . $fixtureName);
        }
    }

    /** @return array<string, array{payload: array}> */
    private function buildFixtureMap(array $contracts): array
    {
        $map = [];
        foreach ($contracts as $spec) {
            $name = is_string($spec['name'] ?? null) ? $spec['name'] : '';
            if ($name === '') {
                continue;
            }

            $exampleOk = $this->buildFixtureFromExample($spec['example_ok'] ?? null, $name);
            if ($exampleOk !== null) {
                $map[$exampleOk['fixture']] = ['payload' => $exampleOk['payload']];
            }

            $exampleError = $this->buildFixtureFromExample($spec['example_error'] ?? null, $name . '.error');
            if ($exampleError !== null) {
                $map[$exampleError['fixture']] = ['payload' => $exampleError['payload']];
            }
        }

        return $map;
    }

    /** @return array{fixture: string, payload: array}|null */
    private function buildFixtureFromExample(mixed $example, string $defaultFixture): ?array
    {
        if (!is_array($example)) {
            return null;
        }

        $payload = $example['payload'] ?? null;
        if (!is_array($payload)) {
            $payload = $example;
        }
        if (!is_array($payload)) {
            return null;
        }

        $fixture = $example['fixture'] ?? $defaultFixture;
        if (!is_string($fixture) || $fixture === '') {
            $fixture = $defaultFixture;
        }

        return [
            'fixture' => $fixture,
            'payload' => $payload,
        ];
    }
}
