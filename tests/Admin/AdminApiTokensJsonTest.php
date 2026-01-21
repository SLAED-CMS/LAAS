<?php
declare(strict_types=1);

use Laas\Database\DatabaseManager;
use Laas\Domain\ApiTokens\ApiTokensService;
use Laas\Http\Request;
use Laas\Modules\Admin\Controller\ApiTokensController;
use Laas\View\View;
use PHPUnit\Framework\TestCase;
use Tests\Security\Support\SecurityTestHelper;
use Tests\Support\InMemorySession;

final class AdminApiTokensJsonTest extends TestCase
{
    public function testIndexReturnsJsonContract(): void
    {
        $pdo = $this->createBaseSchema();
        $this->seedPermission($pdo, 'api_tokens.view', 1);
        $pdo->exec("INSERT INTO api_tokens (user_id, name, token_hash, token_prefix, scopes, last_used_at, expires_at, revoked_at, created_at, updated_at)
            VALUES (1, 'CLI', 'hash', 'ABCDEF123456', '[\"admin.read\"]', '2026-01-01 00:00:00', NULL, NULL, '2026-01-01 00:00:00', '2026-01-01 00:00:00')");

        $request = $this->makeRequest('GET', '/admin/api-tokens');
        $controller = $this->createController($pdo, $request);

        $response = $controller->index($request);

        $this->assertSame(200, $response->getStatus());
        $this->assertSame('application/json; charset=utf-8', $response->getHeader('Content-Type'));
        $payload = json_decode($response->getBody(), true);
        $this->assertSame('json', $payload['meta']['format'] ?? null);
        $this->assertSame('admin.api_tokens.index', $payload['meta']['route'] ?? null);
        $this->assertIsArray($payload['data']['items'] ?? null);
        $this->assertIsArray($payload['data']['counts'] ?? null);
    }

    private function createBaseSchema(): \PDO
    {
        $pdo = SecurityTestHelper::createSqlitePdo();
        $pdo->exec('CREATE TABLE settings (id INTEGER PRIMARY KEY AUTOINCREMENT, `key` VARCHAR(255) UNIQUE, `value` TEXT NULL, `type` VARCHAR(20) NULL, updated_at DATETIME NULL)');
        $pdo->exec('CREATE TABLE api_tokens (
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
        SecurityTestHelper::seedRbacTables($pdo);
        return $pdo;
    }

    private function seedPermission(\PDO $pdo, string $permission, int $userId): void
    {
        SecurityTestHelper::insertUser($pdo, $userId, 'admin', 'hash');
        SecurityTestHelper::insertRole($pdo, 1, 'admin');
        SecurityTestHelper::insertPermission($pdo, 1, $permission);
        SecurityTestHelper::assignRole($pdo, $userId, 1);
        SecurityTestHelper::grantPermission($pdo, 1, 1);
    }

    private function makeRequest(string $method, string $path): Request
    {
        $request = new Request($method, $path, [], [], ['accept' => 'application/json'], '');
        $session = new InMemorySession();
        $session->start();
        $session->set('user_id', 1);
        $request->setSession($session);
        return $request;
    }

    private function createController(\PDO $pdo, Request $request): ApiTokensController
    {
        $db = SecurityTestHelper::dbManagerFromPdo($pdo);
        $view = $this->createView($db, $request);
        $container = SecurityTestHelper::createContainer($db);
        $service = $this->createService($db);
        return new ApiTokensController($view, $service, $service, $container);
    }

    private function createView(DatabaseManager $db, Request $request): View
    {
        return SecurityTestHelper::createView($db, $request, 'admin');
    }

    private function createService(DatabaseManager $db): ApiTokensService
    {
        return new ApiTokensService($db, [
            'token_scopes' => ['admin.read'],
        ], SecurityTestHelper::rootPath());
    }
}
