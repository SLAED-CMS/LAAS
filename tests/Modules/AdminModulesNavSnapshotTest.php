<?php

declare(strict_types=1);

use Laas\Core\Container\Container;
use Laas\Database\DatabaseManager;
use Laas\Database\Repositories\ModulesRepository;
use Laas\Modules\AdminModulesNavSnapshot;
use Laas\Support\RequestScope;
use PHPUnit\Framework\TestCase;

final class AdminModulesNavSnapshotTest extends TestCase
{
    protected function tearDown(): void
    {
        RequestScope::setRequest(null);
        RequestScope::reset();
    }

    public function testLoadUsesExistingSnapshot(): void
    {
        $cachePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'laas-admin-modules-nav-' . bin2hex(random_bytes(4)) . '.php';
        $payload = [
            'generated_at' => time(),
            'nav' => [
                ['name' => 'Admin', 'key' => 'Admin'],
            ],
            'sections' => [
                ['key' => 'core', 'title' => 'Core', 'items' => []],
            ],
        ];
        file_put_contents($cachePath, "<?php\n\nreturn " . var_export($payload, true) . ";\n");

        $snapshot = new AdminModulesNavSnapshot($cachePath, __DIR__);

        $data = $snapshot->load();
        $this->assertIsArray($data);
        $this->assertSame($payload['nav'], $data['nav']);
        $this->assertSame($payload['sections'], $data['sections']);
    }

    public function testInvalidateAndRebuild(): void
    {
        $cachePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'laas-admin-modules-nav-invalidate-' . bin2hex(random_bytes(4)) . '.php';

        $db = new DatabaseManager([
            'driver' => 'sqlite',
            'database' => ':memory:',
        ]);
        $pdo = $db->pdo();
        $pdo->exec(
            'CREATE TABLE modules (
                name TEXT PRIMARY KEY,
                enabled INTEGER,
                version TEXT,
                installed_at TEXT,
                updated_at TEXT
            )'
        );

        $snapshot = new AdminModulesNavSnapshot($cachePath, dirname(__DIR__, 2), $db, [], []);
        RequestScope::set('modules.admin_nav_snapshot', $snapshot);

        $repo = new ModulesRepository($pdo);
        $repo->enable('Admin');

        $this->assertFileDoesNotExist($cachePath);

        $data = $snapshot->rebuild();

        $this->assertFileExists($cachePath);
        $this->assertIsArray($data['nav']);
        $this->assertIsArray($data['sections']);
    }
}
