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

final class AdminApiTokensIndexUsesServiceTest extends TestCase
{
    public function testIndexUsesService(): void
    {
        $pdo = $this->createBaseSchema();
        $this->seedPermission($pdo, 'api_tokens.view', 1);

        $request = $this->makeRequest('GET', '/admin/api-tokens');
        $db = SecurityTestHelper::dbManagerFromPdo($pdo);
        $view = $this->createView($db, $request);
        $service = new SpyApiTokensService($db, [
            'token_scopes' => ['admin.read'],
        ], SecurityTestHelper::rootPath());
        $controller = new ApiTokensController($view, $db, $service);

        $response = $controller->index($request);

        $this->assertSame(200, $response->getStatus());
        $this->assertTrue($service->listTokensCalled);
        $this->assertTrue($service->countTokensCalled);
    }

    private function createBaseSchema(): \PDO
    {
        $pdo = SecurityTestHelper::createSqlitePdo();
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

    private function createView(DatabaseManager $db, Request $request): View
    {
        return SecurityTestHelper::createView($db, $request, 'admin');
    }
}

final class SpyApiTokensService extends ApiTokensService
{
    public bool $listTokensCalled = false;
    public bool $countTokensCalled = false;

    public function listTokens(?int $userId = null, int $limit = 100, int $offset = 0): array
    {
        $this->listTokensCalled = true;
        return [];
    }

    public function countTokens(?int $userId = null): int
    {
        $this->countTokensCalled = true;
        return 0;
    }
}
