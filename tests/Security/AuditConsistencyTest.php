<?php
declare(strict_types=1);

require_once __DIR__ . '/Support/SecurityTestHelper.php';

use Laas\Database\DatabaseManager;
use Laas\Domain\ApiTokens\ApiTokensService;
use Laas\Http\Request;
use Laas\Modules\Admin\Controller\ApiTokensController;
use Laas\Support\RequestScope;
use Laas\View\View;
use Laas\Database\Repositories\RbacRepository;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\Security\Support\SecurityTestHelper;
use Tests\Support\InMemorySession;

#[Group('security')]
final class AuditConsistencyTest extends TestCase
{
    public function testTokenCreateAndRevokeWriteAudit(): void
    {
        RequestScope::reset();
        $_SERVER['REMOTE_ADDR'] = '127.0.0.14';

        $pdo = $this->createBaseSchema();
        $this->seedPermissions($pdo, 1, ['api_tokens.create', 'api_tokens.revoke']);
        $db = SecurityTestHelper::dbManagerFromPdo($pdo);
        $rbac = new RbacRepository($db->pdo());
        $this->assertTrue($rbac->userHasPermission(1, 'api_tokens.create'));
        $this->assertTrue($rbac->userHasPermission(1, 'api_tokens.revoke'));

        $createRequest = $this->makeRequest('POST', '/admin/api-tokens', [
            'name' => 'CLI',
            'scopes' => ['admin.read'],
        ]);
        RequestScope::setRequest($createRequest);
        RequestScope::set('db.manager', $db);
        $controller = $this->createController($db, $createRequest);
        $createResponse = $controller->create($createRequest);

        $this->assertSame(201, $createResponse->getStatus());
        $payload = json_decode($createResponse->getBody(), true);
        $tokenId = (int) ($payload['data']['token_id'] ?? 0);
        $this->assertGreaterThan(0, $tokenId);

        $revokeRequest = $this->makeRequest('POST', '/admin/api-tokens/revoke', [
            'id' => (string) $tokenId,
        ]);
        RequestScope::forget('db.healthcheck');
        RequestScope::setRequest($revokeRequest);
        RequestScope::set('db.manager', $db);
        $revokeResponse = $controller->revoke($revokeRequest);
        RequestScope::setRequest(null);

        $this->assertSame(200, $revokeResponse->getStatus());

        $rows = $pdo->query('SELECT action, context FROM audit_logs ORDER BY id ASC')->fetchAll();
        $actions = array_map(static fn(array $row): string => (string) ($row['action'] ?? ''), $rows ?: []);
        $this->assertContains('api_tokens.create', $actions);
        $this->assertContains('api_tokens.revoke', $actions);

        $contexts = array_map(static function (array $row): array {
            $raw = (string) ($row['context'] ?? '');
            $decoded = json_decode($raw, true);
            return is_array($decoded) ? $decoded : [];
        }, $rows ?: []);
        $actorIds = array_map(static fn(array $ctx): ?int => isset($ctx['actor_user_id']) ? (int) $ctx['actor_user_id'] : null, $contexts);
        $this->assertContains(1, $actorIds);
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
        SecurityTestHelper::seedAuditTable($pdo);
        return $pdo;
    }

    private function seedPermissions(\PDO $pdo, int $userId, array $permissions): void
    {
        SecurityTestHelper::insertUser($pdo, $userId, 'admin', 'hash');
        SecurityTestHelper::insertRole($pdo, 1, 'admin');
        SecurityTestHelper::assignRole($pdo, $userId, 1);
        $permId = 1;
        foreach ($permissions as $permission) {
            SecurityTestHelper::insertPermission($pdo, $permId, $permission);
            SecurityTestHelper::grantPermission($pdo, 1, $permId);
            $permId++;
        }
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

    private function createController(DatabaseManager $db, Request $request): ApiTokensController
    {
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
