<?php
declare(strict_types=1);

use Laas\Http\Request;
use Laas\Http\RequestContext;
use Laas\Perf\PerfBudgetResult;
use PHPUnit\Framework\TestCase;
use Tests\Security\Support\SecurityTestHelper;

abstract class PerfBudgetTestCase extends TestCase
{
    protected array $envBackup = [];
    private ?string $dbPath = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->envBackup = $_ENV;
    }

    protected function tearDown(): void
    {
        $this->restoreEnv($this->envBackup);
        $this->stopSession();
        $this->cleanupDb();
        RequestContext::resetMetrics();
        parent::tearDown();
    }

    protected function prepareDatabase(string $suffix, callable $seeder): string
    {
        $path = $this->tempDbPath($suffix);
        $pdo = $this->createPdo($path);
        $seeder($pdo);
        $this->dbPath = $path;
        return $path;
    }

    protected function createPdo(string $path): PDO
    {
        $pdo = new PDO('sqlite:' . $path);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        return $pdo;
    }

    protected function seedRbac(PDO $pdo, array $permissions, int $userId = 1, int $roleId = 1): void
    {
        SecurityTestHelper::seedRbacTables($pdo);
        SecurityTestHelper::insertUser($pdo, $userId, 'admin', 'hash');
        SecurityTestHelper::insertRole($pdo, $roleId, 'admin');

        $permId = 1;
        foreach ($permissions as $permission) {
            SecurityTestHelper::insertPermission($pdo, $permId, (string) $permission);
            SecurityTestHelper::grantPermission($pdo, $roleId, $permId);
            $permId++;
        }

        SecurityTestHelper::assignRole($pdo, $userId, $roleId);
    }

    protected function seedModulesTable(PDO $pdo): void
    {
        $pdo->exec('CREATE TABLE modules (
            name TEXT PRIMARY KEY,
            enabled INTEGER NOT NULL DEFAULT 0,
            version TEXT NULL,
            installed_at TEXT NULL,
            updated_at TEXT NOT NULL
        )');
        $pdo->exec("INSERT INTO modules (name, enabled, version, installed_at, updated_at) VALUES ('Admin', 1, '1.0.0', '2026-01-01', '2026-01-01')");
    }

    protected function seedPagesTable(PDO $pdo): void
    {
        SecurityTestHelper::seedPagesTable($pdo);
        $pdo->exec("INSERT INTO pages (id, title, slug, status, content, created_at, updated_at) VALUES (1, 'Sample', 'sample', 'published', 'Body', '2026-01-01', '2026-01-01')");
    }

    protected function seedPagesRevisions(PDO $pdo): void
    {
        $pdo->exec('CREATE TABLE pages_revisions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            page_id INTEGER,
            blocks_json TEXT,
            created_at TEXT,
            created_by INTEGER
        )');
        $pdo->exec("INSERT INTO pages_revisions (page_id, blocks_json, created_at, created_by) VALUES (1, '[]', '2026-01-01', 1)");
    }

    protected function setDatabaseEnv(string $path): void
    {
        $_ENV['DB_DRIVER'] = 'sqlite';
        $_ENV['DB_DATABASE'] = $path;
        $_ENV['DB_NAME'] = $path;
        putenv('DB_DRIVER=sqlite');
        putenv('DB_DATABASE=' . $path);
        putenv('DB_NAME=' . $path);
    }

    protected function startSession(int $userId): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        session_id('perf-' . uniqid('', true));
        session_start();
        $_SESSION = [];
        $_SESSION['user_id'] = $userId;
    }

    protected function stopSession(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return;
        }

        $_SESSION = [];
        session_write_close();
    }

    /**
     * @param array<string, string> $headers
     */
    protected function makeRequest(string $path, array $headers = []): Request
    {
        $headers = array_change_key_case($headers, CASE_LOWER);
        if (!isset($headers['accept'])) {
            $headers['accept'] = 'text/html';
        }
        return new Request('GET', $path, [], [], $headers, '');
    }

    protected function formatViolations(PerfBudgetResult $result): string
    {
        $violations = $result->getViolations();
        if ($violations === []) {
            return '';
        }
        $json = json_encode($violations, JSON_UNESCAPED_SLASHES);
        return is_string($json) ? $json : 'perf_budget_violations';
    }

    private function restoreEnv(array $env): void
    {
        $keys = array_unique(array_merge(array_keys($_ENV), array_keys($env)));
        foreach ($keys as $key) {
            if (!array_key_exists($key, $env)) {
                unset($_ENV[$key]);
                putenv($key);
                continue;
            }
            $_ENV[$key] = $env[$key];
            putenv($key . '=' . (string) $env[$key]);
        }
    }

    private function tempDbPath(string $suffix): string
    {
        $root = rtrim(sys_get_temp_dir(), '/\\') . '/laas-perf-' . $suffix . '-' . uniqid('', true);
        mkdir($root, 0775, true);
        return $root . '/db.sqlite';
    }

    private function cleanupDb(): void
    {
        if ($this->dbPath === null) {
            return;
        }

        if (is_file($this->dbPath)) {
            unlink($this->dbPath);
        }

        $dir = dirname($this->dbPath);
        if (is_dir($dir)) {
            @rmdir($dir);
        }

        $this->dbPath = null;
    }
}
