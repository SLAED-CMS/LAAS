<?php

declare(strict_types=1);

use Laas\Database\DatabaseManager;
use Laas\Modules\AdminModulesNavSnapshot;
use Laas\Support\RequestScope;
use PHPUnit\Framework\TestCase;

final class AdminModulesNavSnapshotBudgetTest extends TestCase
{
    protected function tearDown(): void
    {
        RequestScope::setRequest(null);
        RequestScope::reset();
    }

    public function testAdminRefreshDoesNotRebuildNavSnapshot(): void
    {
        $cachePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'laas-admin-modules-nav-perf-' . bin2hex(random_bytes(4)) . '.php';
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
        $snapshot->rebuild();

        RequestScope::reset();
        $data = $snapshot->load();
        $this->assertIsArray($data);

        $stats = RequestScope::get('devtools.modules');
        $calls = is_array($stats) ? (int) ($stats['admin_nav']['calls'] ?? 0) : 0;
        $ms = is_array($stats) ? (float) ($stats['admin_nav']['ms'] ?? 0.0) : 0.0;

        $this->assertTrue($calls === 0 || $ms <= 0.5);
    }
}
