<?php
declare(strict_types=1);

use Laas\Database\DatabaseManager;
use Laas\Domain\Settings\SettingsService;
use PHPUnit\Framework\TestCase;

final class SettingsServiceTest extends TestCase
{
    public function testGetSetAndSetMany(): void
    {
        $db = $this->createDb();
        $service = new SettingsService($db);

        $this->assertSame('LAAS CMS', $service->get('site_name'));

        $service->set('site_name', 'My Site');
        $this->assertSame('My Site', $service->get('site_name'));

        $service->setMany([
            'default_locale' => 'de',
            'theme' => 'default',
        ]);

        $this->assertSame('de', $service->get('default_locale'));
        $this->assertSame('default', $service->get('theme'));
    }

    public function testListReturnsNormalizedItems(): void
    {
        $db = $this->createDb();
        $service = new SettingsService($db);

        $items = $service->list();
        $this->assertNotEmpty($items);
        $first = $items[0] ?? [];
        $this->assertArrayHasKey('key', $first);
        $this->assertArrayHasKey('value', $first);
        $this->assertArrayHasKey('source', $first);
    }

    private function createDb(): DatabaseManager
    {
        $db = new DatabaseManager(['driver' => 'sqlite', 'database' => ':memory:']);
        $pdo = $db->pdo();
        $pdo->exec('CREATE TABLE settings (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            `key` TEXT UNIQUE,
            `value` TEXT NULL,
            `type` TEXT NULL,
            updated_at TEXT NULL
        )');

        return $db;
    }
}
