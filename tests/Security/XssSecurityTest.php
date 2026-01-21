<?php
declare(strict_types=1);

require_once __DIR__ . '/Support/SecurityTestHelper.php';

use Laas\Http\Request;
use Laas\Modules\Menu\Controller\AdminMenusController;
use Laas\Modules\Media\Controller\AdminMediaController;
use Laas\Modules\Pages\Controller\PagesController;
use Laas\Domain\Pages\PagesService;
use Laas\Domain\Menus\MenusService;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\Security\Support\SecurityTestHelper;
use Tests\Support\InMemorySession;

#[Group('security')]
final class XssSecurityTest extends TestCase
{
    private string $rootPath;

    protected function setUp(): void
    {
        $this->rootPath = SecurityTestHelper::rootPath();
    }

    public function testPagesSearchEscapesStoredContent(): void
    {
        $pdo = SecurityTestHelper::createSqlitePdo();
        SecurityTestHelper::seedPagesTable($pdo);
        $pdo->exec("INSERT INTO pages (id, title, slug, status, content, created_at, updated_at) VALUES (1, '<script>alert(1)</script>', 'hello', 'published', 'Safe', '2026-01-01 00:00:00', '2026-01-01 00:00:00')");
        $db = SecurityTestHelper::dbManagerFromPdo($pdo);

        $request = new Request('GET', '/search', ['q' => 'alert'], [], [], '');
        $view = SecurityTestHelper::createView($db, $request, 'default');
        $controller = new PagesController($view, new PagesService($db));

        $response = $controller->search($request);
        $body = $response->getBody();

        $this->assertStringNotContainsString('<script>', $body);
        $this->assertStringContainsString('&lt;script&gt;', $body);
    }

    public function testMediaAdminListEscapesOriginalName(): void
    {
        $pdo = SecurityTestHelper::createSqlitePdo();
        SecurityTestHelper::seedRbacTables($pdo);
        SecurityTestHelper::seedMediaTable($pdo);

        SecurityTestHelper::insertUser($pdo, 1, 'admin', password_hash('secret', PASSWORD_DEFAULT));
        SecurityTestHelper::insertRole($pdo, 1, 'admin');
        SecurityTestHelper::insertPermission($pdo, 1, 'media.view');
        SecurityTestHelper::assignRole($pdo, 1, 1);
        SecurityTestHelper::grantPermission($pdo, 1, 1);

        $pdo->exec("INSERT INTO media_files (id, uuid, disk_path, original_name, mime_type, size_bytes, sha256, uploaded_by, created_at, is_public) VALUES (1, 'u', 'uploads/x.png', '<img src=x onerror=alert(1)>', 'image/png', 12, 'hash', 1, '2026-01-01 00:00:00', 0)");
        $db = SecurityTestHelper::dbManagerFromPdo($pdo);

        $request = new Request('GET', '/admin/media', [], [], [], '');
        $this->attachSession($request, 1);
        $view = SecurityTestHelper::createView($db, $request, 'admin');
        $container = SecurityTestHelper::createContainer($db);
        $controller = new AdminMediaController($view, null, null, $container);

        $response = $controller->index($request);
        $body = $response->getBody();

        $this->assertStringNotContainsString('<img src=x onerror=alert(1)>', $body);
        $this->assertStringContainsString('&lt;img', $body);
    }

    public function testMenusAdminEscapesLabel(): void
    {
        $pdo = SecurityTestHelper::createSqlitePdo();
        SecurityTestHelper::seedRbacTables($pdo);
        SecurityTestHelper::seedMenusTables($pdo);

        SecurityTestHelper::insertUser($pdo, 1, 'admin', password_hash('secret', PASSWORD_DEFAULT));
        SecurityTestHelper::insertRole($pdo, 1, 'admin');
        SecurityTestHelper::insertPermission($pdo, 1, 'menus.edit');
        SecurityTestHelper::assignRole($pdo, 1, 1);
        SecurityTestHelper::grantPermission($pdo, 1, 1);

        $pdo->exec("INSERT INTO menus (id, name, title, created_at, updated_at) VALUES (1, 'main', 'Main', '2026-01-01 00:00:00', '2026-01-01 00:00:00')");
        $pdo->exec("INSERT INTO menu_items (id, menu_id, label, url, sort_order, enabled, is_external, created_at, updated_at) VALUES (1, 1, '<script>alert(1)</script>', '/', 1, 1, 0, '2026-01-01 00:00:00', '2026-01-01 00:00:00')");
        $db = SecurityTestHelper::dbManagerFromPdo($pdo);

        $request = new Request('GET', '/admin/menus', [], [], [], '');
        $this->attachSession($request, 1);
        $view = SecurityTestHelper::createView($db, $request, 'admin');
        $container = SecurityTestHelper::createContainer($db);
        $service = new MenusService($db);
        $controller = new AdminMenusController($view, $service, $service, $container);

        $response = $controller->index($request);
        $body = $response->getBody();

        $this->assertStringNotContainsString('<script>', $body);
        $this->assertStringContainsString('&lt;script&gt;', $body);
    }

    private function attachSession(Request $request, int $userId): void
    {
        $session = new InMemorySession();
        $session->start();
        $session->set('user_id', $userId);
        $request->setSession($session);
    }
}
