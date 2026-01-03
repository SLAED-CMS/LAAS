<?php
declare(strict_types=1);

use Laas\Database\Repositories\RbacRepository;
use Laas\Support\Cache\CacheFactory;
use Laas\Support\Cache\CacheKey;
use PHPUnit\Framework\TestCase;

final class PermissionsCacheTest extends TestCase
{
    public function testPermissionsCacheInvalidatedOnRoleUpdate(): void
    {
        $root = dirname(__DIR__);
        $cache = CacheFactory::create($root);
        $cache->delete(CacheKey::permissionsRole(1));

        $dbPath = sys_get_temp_dir() . '/laas_perm_' . bin2hex(random_bytes(4)) . '.sqlite';
        $pdo = new PDO('sqlite:' . $dbPath);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        $pdo->exec('CREATE TABLE roles (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, title TEXT, created_at TEXT, updated_at TEXT)');
        $pdo->exec('CREATE TABLE permissions (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, title TEXT, created_at TEXT, updated_at TEXT)');
        $pdo->exec('CREATE TABLE permission_role (role_id INTEGER, permission_id INTEGER)');

        $pdo->exec("INSERT INTO roles (id, name, title, created_at, updated_at) VALUES (1, 'admin', 'Admin', '2026-01-01 00:00:00', '2026-01-01 00:00:00')");
        $pdo->exec("INSERT INTO permissions (id, name, title, created_at, updated_at) VALUES (1, 'pages.edit', 'Pages edit', '2026-01-01 00:00:00', '2026-01-01 00:00:00')");
        $pdo->exec("INSERT INTO permissions (id, name, title, created_at, updated_at) VALUES (2, 'media.view', 'Media view', '2026-01-01 00:00:00', '2026-01-01 00:00:00')");
        $pdo->exec("INSERT INTO permission_role (role_id, permission_id) VALUES (1, 1)");

        $rbac = new RbacRepository($pdo);
        $this->assertSame(['pages.edit'], $rbac->listRolePermissions(1));
        $this->assertSame(['pages.edit'], $cache->get(CacheKey::permissionsRole(1)));

        $rbac->setRolePermissions(1, [2]);
        $this->assertNull($cache->get(CacheKey::permissionsRole(1)));
    }
}
