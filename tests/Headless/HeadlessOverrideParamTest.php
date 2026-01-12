<?php
declare(strict_types=1);

use Laas\Http\Request;
use Laas\Modules\Admin\Controller\ModulesController;
use Laas\Support\RequestScope;
use PHPUnit\Framework\TestCase;
use Tests\Security\Support\SecurityTestHelper;
use Tests\Support\InMemorySession;

final class HeadlessOverrideParamTest extends TestCase
{
    public function testHtmlOverrideAllowsAdminHtmlInDev(): void
    {
        $prevHeadless = $_ENV['APP_HEADLESS'] ?? null;
        $prevAllowlist = $_ENV['APP_HEADLESS_HTML_ALLOWLIST'] ?? null;
        $prevOverride = $_ENV['APP_HEADLESS_HTML_OVERRIDE_PARAM'] ?? null;
        $prevEnv = $_ENV['APP_ENV'] ?? null;
        $_ENV['APP_HEADLESS'] = 'true';
        $_ENV['APP_HEADLESS_HTML_ALLOWLIST'] = '';
        $_ENV['APP_HEADLESS_HTML_OVERRIDE_PARAM'] = '_html';
        $_ENV['APP_ENV'] = 'dev';

        try {
            $pdo = $this->createBaseSchema();
            $this->seedModulesManage($pdo, 1);

            $request = $this->makeRequest('GET', '/admin/modules', ['_html' => '1']);
            RequestScope::setRequest($request);
            $controller = $this->createController($pdo, $request);

            $response = $controller->index($request);

            $this->assertSame(200, $response->getStatus());
            $this->assertSame('text/html; charset=utf-8', $response->getHeader('Content-Type'));
            $this->assertNotSame('', $response->getBody());
        } finally {
            RequestScope::reset();
            RequestScope::setRequest(null);
            if ($prevHeadless === null) {
                unset($_ENV['APP_HEADLESS']);
            } else {
                $_ENV['APP_HEADLESS'] = $prevHeadless;
            }
            if ($prevAllowlist === null) {
                unset($_ENV['APP_HEADLESS_HTML_ALLOWLIST']);
            } else {
                $_ENV['APP_HEADLESS_HTML_ALLOWLIST'] = $prevAllowlist;
            }
            if ($prevOverride === null) {
                unset($_ENV['APP_HEADLESS_HTML_OVERRIDE_PARAM']);
            } else {
                $_ENV['APP_HEADLESS_HTML_OVERRIDE_PARAM'] = $prevOverride;
            }
            if ($prevEnv === null) {
                unset($_ENV['APP_ENV']);
            } else {
                $_ENV['APP_ENV'] = $prevEnv;
            }
        }
    }

    private function createBaseSchema(): \PDO
    {
        $pdo = SecurityTestHelper::createSqlitePdo();
        if (method_exists($pdo, 'sqliteCreateFunction')) {
            $pdo->sqliteCreateFunction('NOW', static fn(): string => '2026-01-01 00:00:00');
        }
        $pdo->exec('CREATE TABLE settings (id INTEGER PRIMARY KEY AUTOINCREMENT, `key` VARCHAR(255) UNIQUE, `value` TEXT NULL, `type` VARCHAR(20) NULL, updated_at DATETIME NULL)');
        $pdo->exec('CREATE TABLE modules (name TEXT PRIMARY KEY, enabled INTEGER NOT NULL DEFAULT 0, version TEXT NULL, installed_at TEXT NULL, updated_at TEXT NOT NULL)');
        $pdo->exec('CREATE INDEX idx_modules_enabled ON modules (enabled)');
        SecurityTestHelper::seedRbacTables($pdo);
        return $pdo;
    }

    private function seedModulesManage(\PDO $pdo, int $userId): void
    {
        SecurityTestHelper::insertUser($pdo, $userId, 'admin', 'hash');
        SecurityTestHelper::insertRole($pdo, 1, 'admin');
        SecurityTestHelper::insertPermission($pdo, 1, 'admin.modules.manage');
        SecurityTestHelper::assignRole($pdo, $userId, 1);
        SecurityTestHelper::grantPermission($pdo, 1, 1);
    }

    private function makeRequest(string $method, string $path, array $query): Request
    {
        $request = new Request($method, $path, $query, [], ['accept' => 'application/json'], '');
        $session = new InMemorySession();
        $session->start();
        $session->set('user_id', 1);
        $request->setSession($session);
        return $request;
    }

    private function createController(\PDO $pdo, Request $request): ModulesController
    {
        $db = SecurityTestHelper::dbManagerFromPdo($pdo);
        $view = SecurityTestHelper::createView($db, $request, 'admin');
        return new ModulesController($view, $db);
    }
}
