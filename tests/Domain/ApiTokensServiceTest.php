<?php
declare(strict_types=1);

use Laas\Database\DatabaseManager;
use Laas\Domain\ApiTokens\ApiTokensService;
use Laas\Domain\ApiTokens\ApiTokensServiceException;
use PHPUnit\Framework\TestCase;
use Tests\Security\Support\SecurityTestHelper;

final class ApiTokensServiceTest extends TestCase
{
    public function testCreateAndListTokens(): void
    {
        $db = $this->createDb();
        $service = $this->createService($db, [
            'token_scopes' => ['admin.read'],
        ]);

        $created = $service->createToken(1, 'CLI', ['admin.read'], null);
        $this->assertGreaterThan(0, (int) ($created['token_id'] ?? 0));
        $this->assertNotEmpty((string) ($created['token'] ?? ''));

        $rows = $service->listTokens(1);
        $this->assertCount(1, $rows);
        $this->assertSame('active', $rows[0]['status'] ?? null);
        $this->assertSame(['admin.read'], $rows[0]['scopes'] ?? []);
    }

    public function testValidationFailsForEmptyName(): void
    {
        $db = $this->createDb();
        $service = $this->createService($db, [
            'token_scopes' => ['admin.read'],
        ]);

        try {
            $service->createToken(1, '', ['admin.read'], null);
            $this->fail('Expected validation exception.');
        } catch (ApiTokensServiceException $e) {
            $this->assertSame('validation', $e->errorCode());
            $this->assertArrayHasKey('fields', $e->details());
        }
    }

    public function testRevokeNotFound(): void
    {
        $db = $this->createDb();
        $service = $this->createService($db, []);

        try {
            $service->revokeToken(999, 1);
            $this->fail('Expected not_found exception.');
        } catch (ApiTokensServiceException $e) {
            $this->assertSame('not_found', $e->errorCode());
        }
    }

    public function testLimitEnforcedWhenConfigured(): void
    {
        $db = $this->createDb();
        $service = $this->createService($db, [
            'token_scopes' => ['admin.read'],
            'token_limit' => 1,
        ]);

        $service->createToken(1, 'CLI', ['admin.read'], null);

        try {
            $service->createToken(1, 'CLI-2', ['admin.read'], null);
            $this->fail('Expected limit exception.');
        } catch (ApiTokensServiceException $e) {
            $this->assertSame('limit', $e->errorCode());
        }
    }

    private function createDb(): DatabaseManager
    {
        $db = new DatabaseManager(['driver' => 'sqlite', 'database' => ':memory:']);
        $db->pdo()->exec('CREATE TABLE api_tokens (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INT NOT NULL,
            name TEXT NOT NULL,
            token_hash CHAR(64) NOT NULL UNIQUE,
            token_prefix TEXT NOT NULL,
            scopes TEXT NULL,
            last_used_at DATETIME NULL,
            expires_at DATETIME NULL,
            revoked_at DATETIME NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL
        )');

        return $db;
    }

    private function createService(DatabaseManager $db, array $config): ApiTokensService
    {
        return new ApiTokensService($db, $config, SecurityTestHelper::rootPath());
    }
}
