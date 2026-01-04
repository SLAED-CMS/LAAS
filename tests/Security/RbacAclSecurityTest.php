<?php
declare(strict_types=1);

require_once __DIR__ . '/Support/SecurityTestHelper.php';

use Laas\Auth\AuthInterface;
use Laas\Auth\AuthorizationService;
use Laas\Database\Repositories\RbacRepository;
use Laas\Http\Request;
use Laas\Http\Middleware\RbacMiddleware;
use Laas\Http\Response;
use Laas\Modules\Admin\Controller\AuditController;
use Laas\Modules\Media\Controller\AdminMediaController;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\Security\Support\SecurityTestHelper;

#[Group('security')]
final class RbacAclSecurityTest extends TestCase
{
    private string $rootPath;

    protected function setUp(): void
    {
        $this->rootPath = SecurityTestHelper::rootPath();
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_unset();
            session_destroy();
        }
    }

    public function testAdminRequiresAdminAccess(): void
    {
        $pdo = SecurityTestHelper::createSqlitePdo();
        SecurityTestHelper::seedRbacTables($pdo);
        SecurityTestHelper::insertUser($pdo, 1, 'admin', password_hash('secret', PASSWORD_DEFAULT));
        SecurityTestHelper::insertRole($pdo, 1, 'admin');
        SecurityTestHelper::assignRole($pdo, 1, 1);
        $db = SecurityTestHelper::dbManagerFromPdo($pdo);

        $auth = new class implements AuthInterface {
            public function attempt(string $username, string $password, string $ip): bool { return false; }
            public function logout(): void {}
            public function user(): ?array { return ['id' => 1, 'username' => 'admin']; }
            public function check(): bool { return true; }
        };

        $request = new Request('GET', '/admin', [], [], [], '');
        $view = SecurityTestHelper::createView($db, $request, 'admin');
        $middleware = new RbacMiddleware($auth, new AuthorizationService(new RbacRepository($pdo)), $view);

        $response = $middleware->process($request, static fn(): Response => new Response('ok', 200));
        $this->assertSame(403, $response->getStatus());
    }

    public function testMediaUploadRequiresPermission(): void
    {
        $pdo = SecurityTestHelper::createSqlitePdo();
        SecurityTestHelper::seedRbacTables($pdo);
        SecurityTestHelper::seedMediaTable($pdo);
        SecurityTestHelper::insertUser($pdo, 1, 'admin', password_hash('secret', PASSWORD_DEFAULT));
        SecurityTestHelper::insertRole($pdo, 1, 'admin');
        SecurityTestHelper::assignRole($pdo, 1, 1);
        $db = SecurityTestHelper::dbManagerFromPdo($pdo);

        SecurityTestHelper::startSession($this->rootPath);
        $_SESSION['user_id'] = 1;

        $request = new Request('POST', '/admin/media/upload', [], [], ['accept' => 'application/json'], '');
        $view = SecurityTestHelper::createView($db, $request, 'admin');
        $controller = new AdminMediaController($view, $db);

        $response = $controller->upload($request);
        $this->assertSame(403, $response->getStatus());
    }

    public function testMediaDeleteRequiresPermission(): void
    {
        $pdo = SecurityTestHelper::createSqlitePdo();
        SecurityTestHelper::seedRbacTables($pdo);
        SecurityTestHelper::seedMediaTable($pdo);
        SecurityTestHelper::insertUser($pdo, 1, 'admin', password_hash('secret', PASSWORD_DEFAULT));
        SecurityTestHelper::insertRole($pdo, 1, 'admin');
        SecurityTestHelper::assignRole($pdo, 1, 1);
        $db = SecurityTestHelper::dbManagerFromPdo($pdo);

        SecurityTestHelper::startSession($this->rootPath);
        $_SESSION['user_id'] = 1;

        $request = new Request('POST', '/admin/media/delete', [], ['id' => '1'], ['accept' => 'application/json'], '');
        $view = SecurityTestHelper::createView($db, $request, 'admin');
        $controller = new AdminMediaController($view, $db);

        $response = $controller->delete($request);
        $this->assertSame(403, $response->getStatus());
    }

    public function testAuditRequiresPermission(): void
    {
        $pdo = SecurityTestHelper::createSqlitePdo();
        SecurityTestHelper::seedRbacTables($pdo);
        SecurityTestHelper::seedAuditTable($pdo);
        SecurityTestHelper::insertUser($pdo, 1, 'admin', password_hash('secret', PASSWORD_DEFAULT));
        SecurityTestHelper::insertRole($pdo, 1, 'admin');
        SecurityTestHelper::assignRole($pdo, 1, 1);
        $db = SecurityTestHelper::dbManagerFromPdo($pdo);

        SecurityTestHelper::startSession($this->rootPath);
        $_SESSION['user_id'] = 1;

        $request = new Request('GET', '/admin/audit', [], [], ['accept' => 'application/json'], '');
        $view = SecurityTestHelper::createView($db, $request, 'admin');
        $controller = new AuditController($view, $db);

        $response = $controller->index($request);
        $this->assertSame(403, $response->getStatus());
    }
}
