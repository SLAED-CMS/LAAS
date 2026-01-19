<?php
declare(strict_types=1);

use Laas\Database\DatabaseManager;
use Laas\Domain\AdminSearch\AdminSearchService;
use Laas\Domain\Media\MediaService;
use Laas\Domain\Menus\MenusService;
use Laas\Domain\Pages\PagesService;
use Laas\Domain\Users\UsersService;
use Laas\Modules\ModuleCatalog;
use PHPUnit\Framework\TestCase;
use Tests\Security\Support\SecurityTestHelper;

final class AdminSearchServiceTest extends TestCase
{
    public function testTooShortQueryReturnsReason(): void
    {
        $service = $this->createService($this->createDb());

        $result = $service->search('a');

        $this->assertSame('too_short', $result['reason'] ?? null);
        $this->assertIsArray($result['groups'] ?? null);
    }

    public function testGroupsExistEvenWhenEmpty(): void
    {
        $service = $this->createService($this->createDb());

        $result = $service->search('page', [
            'can_pages' => false,
            'can_media' => false,
            'can_users' => false,
            'can_menus' => false,
            'can_modules' => false,
            'can_security_reports' => false,
            'can_ops' => false,
        ]);

        $groups = $result['groups'] ?? [];
        $this->assertArrayHasKey('pages', $groups);
        $this->assertArrayHasKey('media', $groups);
        $this->assertArrayHasKey('users', $groups);
        $this->assertArrayHasKey('menus', $groups);
        $this->assertArrayHasKey('modules', $groups);
        $this->assertArrayHasKey('ai', $groups);
        $this->assertArrayHasKey('security_reports', $groups);
        $this->assertArrayHasKey('ops', $groups);
    }

    public function testRespectsLimits(): void
    {
        $db = $this->createDb();
        $pdo = $db->pdo();
        for ($i = 1; $i <= 10; $i++) {
            $pdo->exec("INSERT INTO pages (title, slug, status, content, updated_at) VALUES ('Page {$i}', 'page-{$i}', 'draft', 'x', '2026-01-01 00:00:00')");
        }

        $service = $this->createService($db);
        $result = $service->search('page', [
            'can_pages' => true,
            'group_limit' => 3,
            'global_limit' => 4,
        ]);

        $pages = $result['groups']['pages']['items'] ?? [];
        $this->assertCount(3, $pages);
    }

    public function testModuleCatalogHits(): void
    {
        $service = $this->createService($this->createDb());

        $result = $service->search('pages', [
            'can_modules' => true,
        ]);

        $modules = $result['groups']['modules']['items'] ?? [];
        $this->assertNotEmpty($modules);
    }

    private function createDb(): DatabaseManager
    {
        $db = new DatabaseManager(['driver' => 'sqlite', 'database' => ':memory:']);
        $pdo = $db->pdo();
        $pdo->exec('CREATE TABLE pages (id INTEGER PRIMARY KEY AUTOINCREMENT, title TEXT, slug TEXT, status TEXT, content TEXT, updated_at TEXT)');
        $pdo->exec('CREATE TABLE media_files (id INTEGER PRIMARY KEY AUTOINCREMENT, original_name TEXT, mime_type TEXT, disk_path TEXT, size_bytes INTEGER, created_at TEXT, uploaded_by INTEGER)');
        $pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, username TEXT, email TEXT)');
        $pdo->exec('CREATE TABLE menus (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, title TEXT, created_at TEXT, updated_at TEXT)');

        return $db;
    }

    private function createService(DatabaseManager $db): AdminSearchService
    {
        $rootPath = SecurityTestHelper::rootPath();
        $pages = new PagesService($db);
        $media = new MediaService($db, [], $rootPath);
        $users = new UsersService($db);
        $menus = new MenusService($db);
        $modules = new ModuleCatalog($rootPath, null, null);

        return new AdminSearchService($pages, $media, $users, $menus, $modules);
    }
}
