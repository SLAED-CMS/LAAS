<?php
declare(strict_types=1);

use Laas\Database\DatabaseManager;
use Laas\Domain\Ops\OpsService;
use Laas\Domain\Security\SecurityReportsService;
use PHPUnit\Framework\TestCase;
use Tests\Security\Support\SecurityTestHelper;

final class OpsServiceTest extends TestCase
{
    public function testOverviewReturnsStructuredData(): void
    {
        $db = new DatabaseManager(['driver' => 'sqlite', 'database' => ':memory:']);
        $service = new OpsService(
            $db,
            $this->config(),
            SecurityTestHelper::rootPath(),
            new SecurityReportsService($db)
        );

        $snapshot = $service->overview(false);

        $this->assertIsArray($snapshot);
        $this->assertIsArray($snapshot['health'] ?? null);
        $this->assertIsArray($snapshot['sessions'] ?? null);
        $this->assertIsArray($snapshot['backups'] ?? null);
        $this->assertIsArray($snapshot['performance'] ?? null);
        $this->assertIsArray($snapshot['cache'] ?? null);
        $this->assertIsArray($snapshot['security'] ?? null);
        $this->assertIsArray($snapshot['preflight'] ?? null);
    }

    private function config(): array
    {
        return [
            'app' => [
                'env' => 'test',
                'debug' => false,
                'read_only' => false,
                'headless_mode' => false,
            ],
            'security' => [
                'session' => ['driver' => 'native'],
            ],
            'storage' => [
                'default' => 'local',
            ],
            'media' => [],
            'perf' => [],
        ];
    }
}
