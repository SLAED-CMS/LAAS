<?php
declare(strict_types=1);

use Laas\Database\DatabaseManager;
use Laas\Domain\ApiTokens\ApiTokensService;
use Laas\Http\Request;
use Laas\Modules\Admin\Controller\ApiTokensController;
use Laas\View\View;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\Security\Support\SecurityTestHelper;
use Tests\Support\InMemorySession;

#[Group('api')]
#[Group('security')]
final class ApiTokensControllerTest extends TestCase
{
    public function testRevokeRequiresPermission(): void
    {
        $pdo = SecurityTestHelper::createSqlitePdo();
        $pdo->exec('CREATE TABLE settings (id INTEGER PRIMARY KEY AUTOINCREMENT, `key` VARCHAR(255) UNIQUE, `value` TEXT NULL, `type` VARCHAR(20) NULL, updated_at DATETIME NULL)');
        $pdo->exec('CREATE TABLE roles (id INTEGER PRIMARY KEY AUTOINCREMENT, name VARCHAR(100) UNIQUE, title VARCHAR(150), created_at DATETIME, updated_at DATETIME)');
        $pdo->exec('CREATE TABLE permissions (id INTEGER PRIMARY KEY AUTOINCREMENT, name VARCHAR(100) UNIQUE, title VARCHAR(150), created_at DATETIME, updated_at DATETIME)');
        $pdo->exec('CREATE TABLE role_user (user_id INT NOT NULL, role_id INT NOT NULL)');
        $pdo->exec('CREATE TABLE permission_role (role_id INT NOT NULL, permission_id INT NOT NULL)');
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

        $db = SecurityTestHelper::dbManagerFromPdo($pdo);

        $session = new InMemorySession();
        $session->start();
        $session->set('user_id', 1);

        $request = new Request('POST', '/admin/api/tokens/revoke', [], ['id' => '1'], [], '');
        $request->setSession($session);

        $view = $this->createView($db, $request);
        $service = $this->createService($db);
        $container = SecurityTestHelper::createContainer($db);
        $controller = new ApiTokensController($view, $service, $service, $container);

        $response = $controller->revoke($request);

        $this->assertSame(403, $response->getStatus());
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
