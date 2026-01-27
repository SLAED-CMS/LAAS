<?php
declare(strict_types=1);

use Laas\Database\DatabaseManager;
use Laas\Domain\Pages\PagesService;
use Laas\Domain\Rbac\RbacService;
use Laas\Http\Request;
use Laas\Modules\Pages\Controller\AdminPagesController;
use Laas\View\View;
use PHPUnit\Framework\TestCase;
use Tests\Security\Support\SecurityTestHelper;
use Tests\Support\InMemorySession;

final class AdminPagesBlocksStudioRenderTest extends TestCase
{
    private ?string $previousDebug = null;
    private ?string $previousAssetBase = null;

    protected function setUp(): void
    {
        $this->previousDebug = $_ENV['APP_DEBUG'] ?? null;
        $this->previousAssetBase = $_ENV['ASSET_BASE'] ?? null;
        $_ENV['ASSET_BASE'] = '/_assets_missing';
        $this->clearTemplateCache();
    }

    protected function tearDown(): void
    {
        if ($this->previousDebug === null) {
            unset($_ENV['APP_DEBUG']);
        } else {
            $_ENV['APP_DEBUG'] = $this->previousDebug;
        }
        if ($this->previousAssetBase === null) {
            unset($_ENV['ASSET_BASE']);
        } else {
            $_ENV['ASSET_BASE'] = $this->previousAssetBase;
        }
    }

    public function testBlocksStudioShowsWhenAllowed(): void
    {
        $_ENV['APP_DEBUG'] = 'true';
        $db = $this->createDatabase();
        $this->seedEditor($db->pdo(), 1);

        $request = $this->makeRequest('GET', '/admin/pages/new', 1);
        $view = SecurityTestHelper::createView($db, $request, 'admin');
        $this->shareAdminFeatures($view, true);
        $pages = new PagesService($db);
        $rbac = new RbacService($db);
        $controller = new AdminPagesController($view, $pages, $pages, null, $rbac);

        $response = $controller->createForm($request);

        $this->assertSame(200, $response->getStatus());
        $this->assertStringContainsString('data-blocks-studio="1"', $response->getBody());
        $this->assertStringContainsString('data-blocks-studio-list="1"', $response->getBody());
    }

    public function testBlocksStudioHiddenWhenNotAllowed(): void
    {
        $_ENV['APP_DEBUG'] = 'false';
        $db = $this->createDatabase();
        $this->seedEditor($db->pdo(), 1);

        $request = $this->makeRequest('GET', '/admin/pages/new', 1);
        $view = SecurityTestHelper::createView($db, $request, 'admin');
        $this->shareAdminFeatures($view, false);
        $pages = new PagesService($db);
        $rbac = new RbacService($db);
        $controller = new AdminPagesController($view, $pages, $pages, null, $rbac);

        $response = $controller->createForm($request);

        $this->assertSame(200, $response->getStatus());
        $this->assertStringNotContainsString('data-blocks-studio="1"', $response->getBody());
        $this->assertStringNotContainsString('name="blocks_json"', $response->getBody());
    }

    public function testPageFormRendersEditorSwitchWithoutVendors(): void
    {
        $_ENV['APP_DEBUG'] = 'true';
        $db = $this->createDatabase();
        $this->seedEditor($db->pdo(), 1);

        $request = $this->makeRequest('GET', '/admin/pages/new', 1);
        $view = SecurityTestHelper::createView($db, $request, 'admin');
        $this->shareAdminFeatures($view, false);
        $pages = new PagesService($db);
        $rbac = new RbacService($db);
        $controller = new AdminPagesController($view, $pages, $pages, null, $rbac);

        $response = $controller->createForm($request);

        $this->assertSame(200, $response->getStatus());
        $this->assertStringContainsString('data-editor-choice="1"', $response->getBody());
        $this->assertStringContainsString('data-editor-id="tinymce"', $response->getBody());
        $this->assertStringContainsString('data-editor-id="toastui"', $response->getBody());
        $this->assertStringContainsString('data-editor-selected-id="textarea"', $response->getBody());
        $this->assertStringContainsString('name="content_format"', $response->getBody());
        $this->assertStringContainsString('data-editor-unavailable-hint="1"', $response->getBody());
        $this->assertStringContainsString('data-markdown-editor="1"', $response->getBody());
    }

    public function testPageFormIncludesTinyMceFullConfigTokens(): void
    {
        $_ENV['APP_DEBUG'] = 'true';
        $db = $this->createDatabase();
        $this->seedEditor($db->pdo(), 1);

        $request = $this->makeRequest('GET', '/admin/pages/new', 1);
        $view = SecurityTestHelper::createView($db, $request, 'admin');
        $this->shareAdminFeatures($view, false);
        $pages = new PagesService($db);
        $rbac = new RbacService($db);
        $controller = new AdminPagesController($view, $pages, $pages, null, $rbac);

        $response = $controller->createForm($request);

        $this->assertSame(200, $response->getStatus());
        $this->assertStringContainsString('data-tinymce-config="', $response->getBody());
        $this->assertStringContainsString('toolbar', $response->getBody());
        $this->assertStringContainsString('fullscreen', $response->getBody());
        $this->assertStringContainsString('wordcount', $response->getBody());
    }

    private function createDatabase(): DatabaseManager
    {
        $pdo = SecurityTestHelper::createSqlitePdo();
        SecurityTestHelper::seedRbacTables($pdo);
        SecurityTestHelper::seedPagesTable($pdo);
        SecurityTestHelper::seedSettingsTable($pdo);
        $db = SecurityTestHelper::dbManagerFromPdo($pdo);
        return $db;
    }

    private function clearTemplateCache(): void
    {
        $path = SecurityTestHelper::rootPath() . '/storage/cache/templates';
        if (!is_dir($path)) {
            return;
        }
        foreach (glob($path . '/*.php') as $file) {
            @unlink($file);
        }
    }

    private function seedEditor(PDO $pdo, int $userId): void
    {
        SecurityTestHelper::insertUser($pdo, $userId, 'editor', 'hash');
        SecurityTestHelper::insertRole($pdo, 2, 'editor');
        SecurityTestHelper::insertPermission($pdo, 1, 'pages.edit');
        SecurityTestHelper::assignRole($pdo, $userId, 2);
        SecurityTestHelper::grantPermission($pdo, 2, 1);
    }

    private function makeRequest(string $method, string $path, int $userId): Request
    {
        $request = new Request($method, $path, [], [], [], '');
        $session = new InMemorySession();
        $session->start();
        $session->set('user_id', $userId);
        $request->setSession($session);
        return $request;
    }

    private function shareAdminFeatures(View $view, bool $blocksStudioEnabled): void
    {
        $view->share('admin_features', [
            'palette' => false,
            'blocks_studio' => $blocksStudioEnabled,
            'theme_inspector' => false,
            'headless_playground' => false,
        ]);
    }
}
