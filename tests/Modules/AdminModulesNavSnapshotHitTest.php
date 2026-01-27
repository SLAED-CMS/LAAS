<?php

declare(strict_types=1);

use Laas\Database\DatabaseManager;
use Laas\DevTools\ModulesDiscoveryStats;
use Laas\Modules\AdminModulesNavSnapshot;
use Laas\Support\RequestScope;
use PHPUnit\Framework\TestCase;

final class AdminModulesNavSnapshotHitTest extends TestCase
{
    protected function tearDown(): void
    {
        RequestScope::setRequest(null);
        RequestScope::reset();
    }

    public function testSecondLoadHitsSnapshotWithoutRebuild(): void
    {
        $cachePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'laas-admin-modules-nav-hit-' . bin2hex(random_bytes(4)) . '.php';
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
        ModulesDiscoveryStats::recordMeta('admin_nav_cache', 'miss');
        $snapshot->rebuild();

        RequestScope::reset();

        $data = $snapshot->load();
        $this->assertIsArray($data);

        $stats = RequestScope::get('devtools.modules');
        $calls = is_array($stats) ? (int) ($stats['admin_nav']['calls'] ?? 0) : 0;
        $this->assertSame(0, $calls);

        $meta = RequestScope::get('devtools.modules_meta');
        $this->assertIsArray($meta);
        $this->assertSame('hit', $meta['admin_nav_cache'] ?? null);
    }
}
