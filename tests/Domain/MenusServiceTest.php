<?php
declare(strict_types=1);

use Laas\Content\ContentNormalizer;
use Laas\Content\MarkdownRenderer;
use Laas\Database\DatabaseManager;
use Laas\Domain\Menus\MenusService;
use Laas\Security\HtmlSanitizer;
use PHPUnit\Framework\TestCase;

final class MenusServiceTest extends TestCase
{
    public function testListAndFindMenu(): void
    {
        $db = $this->createDb();
        $service = new MenusService($db);

        $menus = $service->list();
        $this->assertCount(1, $menus);
        $this->assertSame('main', $menus[0]['name'] ?? null);

        $menu = $service->findByName('main');
        $this->assertNotNull($menu);
        $this->assertSame('Main', $menu['title'] ?? null);
    }

    public function testCreateUpdateDeleteMenu(): void
    {
        $db = $this->createDb();
        $service = new MenusService($db);

        $id = $service->create([
            'name' => 'footer',
            'title' => 'Footer',
        ]);
        $this->assertGreaterThan(0, $id);

        $service->update($id, [
            'name' => 'footer',
            'title' => 'Footer Links',
        ]);

        $menu = $service->find($id);
        $this->assertSame('Footer Links', $menu['title'] ?? null);

        $service->delete($id);
        $this->assertNull($service->find($id));
    }

    public function testItemsCrud(): void
    {
        $db = $this->createDb();
        $service = new MenusService($db);
        $menu = $service->findByName('main');
        $menuId = (int) ($menu['id'] ?? 0);
        $this->assertGreaterThan(0, $menuId);

        $itemId = $service->createItem([
            'menu_id' => $menuId,
            'label' => 'Home',
            'url' => '/',
            'enabled' => 1,
            'is_external' => 0,
            'sort_order' => 1,
        ]);
        $this->assertGreaterThan(0, $itemId);

        $service->setItemEnabled($itemId, 0);
        $item = $service->findItem($itemId);
        $this->assertNotNull($item);
        $this->assertFalse($item['enabled'] ?? true);

        $service->updateItem($itemId, [
            'menu_id' => $menuId,
            'label' => 'Home',
            'url' => '/',
            'enabled' => 1,
            'is_external' => 0,
            'sort_order' => 2,
        ]);
        $item = $service->findItem($itemId);
        $this->assertSame(2, $item['sort_order'] ?? null);

        $service->deleteItem($itemId);
        $this->assertNull($service->findItem($itemId));
    }

    public function testItemLabelUnchangedWhenNormalizationDisabled(): void
    {
        $db = $this->createDb();
        $service = new MenusService($db, [
            'app' => [
                'menu_normalize_enabled' => false,
            ],
        ]);
        $menu = $service->findByName('main');
        $menuId = (int) ($menu['id'] ?? 0);
        $input = '**bold** <script>alert(1)</script>';

        $itemId = $service->createItem([
            'menu_id' => $menuId,
            'label' => $input,
            'url' => '/raw',
            'enabled' => 1,
            'is_external' => 0,
            'sort_order' => 1,
            'content_format' => 'markdown',
        ]);

        $item = $service->findItem($itemId);
        $this->assertSame($input, $item['label'] ?? null);
    }

    public function testItemLabelNormalizedWhenEnabled(): void
    {
        $db = $this->createDb();
        $service = new MenusService($db, [
            'app' => [
                'menu_normalize_enabled' => true,
            ],
        ], new ContentNormalizer(
            new MarkdownRenderer(),
            new HtmlSanitizer()
        ));
        $menu = $service->findByName('main');
        $menuId = (int) ($menu['id'] ?? 0);
        $input = '**bold** <script>alert(1)</script><img src="x" onerror="alert(1)">';

        $itemId = $service->createItem([
            'menu_id' => $menuId,
            'label' => $input,
            'url' => '/safe',
            'enabled' => 1,
            'is_external' => 0,
            'sort_order' => 1,
            'content_format' => 'markdown',
        ]);

        $item = $service->findItem($itemId);
        $label = (string) ($item['label'] ?? '');
        $lower = strtolower($label);
        $this->assertStringContainsString('<strong>bold</strong>', $label);
        $this->assertStringNotContainsString('<script', $lower);
        $this->assertStringNotContainsString('onerror', $lower);
    }

    private function createDb(): DatabaseManager
    {
        $db = new DatabaseManager(['driver' => 'sqlite', 'database' => ':memory:']);
        $pdo = $db->pdo();
        $pdo->exec('CREATE TABLE menus (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT,
            title TEXT,
            created_at TEXT,
            updated_at TEXT
        )');
        $pdo->exec('CREATE TABLE menu_items (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            menu_id INTEGER,
            label TEXT,
            url TEXT,
            sort_order INTEGER,
            enabled INTEGER,
            is_external INTEGER,
            created_at TEXT,
            updated_at TEXT
        )');
        $pdo->exec("INSERT INTO menus (name, title, created_at, updated_at) VALUES ('main', 'Main', '2026-01-01 00:00:00', '2026-01-01 00:00:00')");

        return $db;
    }
}
