<?php
declare(strict_types=1);

use Laas\Auth\AuthService;
use Laas\Database\DatabaseManager;
use Laas\Database\Repositories\ModulesRepository;
use Laas\Database\Repositories\UsersRepository;
use Laas\DevTools\DevToolsContext;
use Laas\DevTools\Db\ProxyPDO;
use Laas\Http\Request;
use Laas\Support\RequestScope;
use PHPUnit\Framework\TestCase;
use Tests\Support\InMemorySession;

final class RequestScopeCachingTest extends TestCase
{
    public function testCurrentUserIsCachedPerRequest(): void
    {
        $dbPath = $this->createTempDb();
        $this->seedUsers($dbPath);

        $context = $this->createContext();
        $pdo = $this->createProxySqlite($dbPath, $context);
        $repo = new UsersRepository($pdo);
        $session = new InMemorySession();
        $session->start();
        $session->set('user_id', 1);

        $request = new Request('GET', '/admin', [], [], [], '');
        $request->setSession($session);
        RequestScope::reset();
        RequestScope::setRequest($request);

        $auth = new AuthService($repo, $session);
        $this->assertNotNull($auth->user());
        $this->assertNotNull($auth->user());

        $db = $context->toArray()['db'] ?? [];
        $this->assertSame(1, (int) ($db['count'] ?? 0));

        $this->cleanup($dbPath);
    }

    public function testModulesListIsCachedPerRequest(): void
    {
        $dbPath = $this->createTempDb();
        $this->seedModules($dbPath);

        $context = $this->createContext();
        $pdo = $this->createProxySqlite($dbPath, $context);
        $repo = new ModulesRepository($pdo);

        $request = new Request('GET', '/admin/modules', [], [], [], '');
        RequestScope::reset();
        RequestScope::setRequest($request);

        $repo->all();
        $repo->all();

        $db = $context->toArray()['db'] ?? [];
        $this->assertSame(1, (int) ($db['count'] ?? 0));

        $this->cleanup($dbPath);
    }

    public function testSelectOneIsLimitedPerRequest(): void
    {
        $dbPath = $this->createTempDb();
        $this->seedUsers($dbPath);

        $context = $this->createContext();
        $pdo = $this->createProxySqlite($dbPath, $context);

        $db = new DatabaseManager(['driver' => 'mysql']);
        $ref = new ReflectionProperty($db, 'pdo');
        $ref->setAccessible(true);
        $ref->setValue($db, $pdo);

        $request = new Request('GET', '/admin', [], [], [], '');
        RequestScope::reset();
        RequestScope::setRequest($request);

        $this->assertTrue($db->healthCheck());
        $this->assertTrue($db->healthCheck());

        $dbState = $context->toArray()['db'] ?? [];
        $this->assertSame(1, (int) ($dbState['count'] ?? 0));

        $this->cleanup($dbPath);
    }

    private function createTempDb(): string
    {
        $path = sys_get_temp_dir() . '/laas_' . bin2hex(random_bytes(6)) . '.sqlite';
        $pdo = new PDO('sqlite:' . $path);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, username TEXT)');
        $pdo->exec('CREATE TABLE modules (name TEXT PRIMARY KEY, enabled INTEGER, version TEXT, installed_at TEXT, updated_at TEXT)');
        return $path;
    }

    private function seedUsers(string $path): void
    {
        $pdo = new PDO('sqlite:' . $path);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec("INSERT INTO users (id, username) VALUES (1, 'admin')");
    }

    private function seedModules(string $path): void
    {
        $pdo = new PDO('sqlite:' . $path);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec("INSERT INTO modules (name, enabled, version, installed_at, updated_at) VALUES ('Admin', 1, '1.0.0', '2026-01-01 00:00:00', '2026-01-01 00:00:00')");
    }

    private function createProxySqlite(string $path, DevToolsContext $context): ProxyPDO
    {
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ];
        return new ProxyPDO('sqlite:' . $path, '', '', $options, $context, true);
    }

    private function createContext(): DevToolsContext
    {
        return new DevToolsContext([
            'enabled' => true,
            'debug' => true,
            'env' => 'dev',
            'collect_db' => true,
            'collect_request' => false,
            'collect_logs' => false,
            'request_id' => bin2hex(random_bytes(8)),
        ]);
    }

    private function cleanup(string $path): void
    {
        if (is_file($path)) {
            @unlink($path);
        }
        RequestScope::reset();
        RequestScope::setRequest(null);
    }
}
