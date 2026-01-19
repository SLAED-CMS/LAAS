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

final class AdminApiTokensCreateJsonTest extends TestCase
{
    public function testCreateReturnsTokenOnce(): void
    {
        $pdo = $this->createBaseSchema();
        $this->seedPermission($pdo, 'api_tokens.create', 1);

        $request = $this->makeRequest('POST', '/admin/api-tokens', [
            'name' => 'CLI',
            'scopes' => ['admin.read'],
        ]);
        $controller = $this->createController($pdo, $request);

        $response = $controller->create($request);

        $this->assertSame(201, $response->getStatus());
        $payload = json_decode($response->getBody(), true);
        $this->assertSame('admin.api_tokens.create', $payload['meta']['route'] ?? null);
        $this->assertNotEmpty($payload['data']['token_once'] ?? null);
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

    private function makeRequest(string $method, string $path, array $post): Request
    {
        $request = new Request($method, $path, [], $post, ['accept' => 'application/json'], '');
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
        $service = $this->createService($db);
        return new ApiTokensController($view, $db, $service);
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
