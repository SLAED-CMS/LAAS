<?php
declare(strict_types=1);

use Laas\Database\DatabaseManager;
use Laas\Domain\Menus\MenusService;
use Laas\Http\Request;
use Laas\Modules\Api\Controller\MenusV2Controller;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('api')]
final class HeadlessMenusV2Test extends TestCase
{
    public function testMenusIndexDefaultsToMinimalFields(): void
    {
        $db = $this->createDb();
        $service = new MenusService($db);
        $controller = new MenusV2Controller(null, $service);

        $request = new Request('GET', '/api/v2/menus', [], [], [], '');
        $response = $controller->index($request);

        $payload = json_decode($response->getBody(), true);
        $this->assertTrue($payload['ok']);
        $this->assertSame(1, count($payload['data']['items']));
        $item = $payload['data']['items'][0];
        $this->assertArrayHasKey('id', $item);
        $this->assertArrayHasKey('name', $item);
        $this->assertArrayHasKey('title', $item);
        $this->assertArrayNotHasKey('items', $item);
    }

    public function testIncludeMenuItems(): void
    {
        $db = $this->createDb();
        $service = new MenusService($db);
        $controller = new MenusV2Controller(null, $service);

        $request = new Request('GET', '/api/v2/menus', [
            'include' => 'menu',
        ], [], [], '');
        $response = $controller->index($request);

        $payload = json_decode($response->getBody(), true);
        $this->assertTrue($payload['ok']);
        $item = $payload['data']['items'][0];
        $this->assertArrayHasKey('items', $item);
        $this->assertNotEmpty($item['items']);
    }

    private function createDb(): DatabaseManager
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

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

        $pdo->exec("INSERT INTO menus (name, title, created_at, updated_at) VALUES
            ('main', 'Main', '2026-01-01', '2026-01-02')");
        $pdo->exec("INSERT INTO menu_items (menu_id, label, url, sort_order, enabled, is_external, created_at, updated_at) VALUES
            (1, 'Home', '/', 1, 1, 0, '2026-01-01', '2026-01-02')");

        $db = new DatabaseManager(['driver' => 'mysql']);
        $ref = new ReflectionProperty($db, 'pdo');
        $ref->setAccessible(true);
        $ref->setValue($db, $pdo);

        return $db;
    }
}
