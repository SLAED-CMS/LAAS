<?php
declare(strict_types=1);

use Laas\Api\ApiTokenService;
use Laas\Auth\AuthorizationService;
use Laas\Database\DatabaseManager;
use Laas\Database\Repositories\ApiTokensRepository;
use Laas\Database\Repositories\RbacRepository;
use Laas\Http\Middleware\ApiMiddleware;
use Laas\Http\Request;
use Laas\Http\Response;
use Laas\Modules\Api\Controller\AuthController;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\Support\InMemorySession;

#[Group('api')]
#[Group('security')]
final class ApiAuthSecurityTest extends TestCase
{
    public function testRevokedTokenDeniedAndAudited(): void
    {
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        $root = $this->tempRoot();
        $db = $this->createDb($root);

        $service = new ApiTokenService($db);
        $issued = $service->issueToken(1, 'CLI');

        $repo = new ApiTokensRepository($db->pdo());
        $repo->revoke((int) $issued['token_id'], 1);

        $middleware = new ApiMiddleware($db, $this->authorizationWithApiAccess($db), [
            'enabled' => true,
            'cors' => ['enabled' => false],
        ], $root);

        $request = new Request('GET', '/api/v1/me', [], [], [
            'authorization' => 'Bearer ' . $issued['token'],
        ], '');

        $response = $middleware->process($request, static fn (Request $req): Response => new Response('OK', 200));

        $this->assertSame(401, $response->getStatus());
        $auditRow = $this->fetchAuditRow($db);
        $this->assertSame('api.auth.failed', $auditRow['action']);
        $this->assertStringContainsString('revoked', (string) $auditRow['context']);
    }

    public function testExpiredTokenDenied(): void
    {
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        $root = $this->tempRoot();
        $db = $this->createDb($root);

        $service = new ApiTokenService($db);
        $issued = $service->issueToken(1, 'CLI', date('Y-m-d H:i:s', strtotime('-1 day')));

        $middleware = new ApiMiddleware($db, $this->authorizationWithApiAccess($db), [
            'enabled' => true,
            'cors' => ['enabled' => false],
        ], $root);

        $request = new Request('GET', '/api/v1/me', [], [], [
            'authorization' => 'Bearer ' . $issued['token'],
        ], '');

        $response = $middleware->process($request, static fn (Request $req): Response => new Response('OK', 200));

        $this->assertSame(401, $response->getStatus());
        $auditRow = $this->fetchAuditRow($db);
        $this->assertSame('api.auth.failed', $auditRow['action']);
        $this->assertStringContainsString('expired', (string) $auditRow['context']);
    }

    public function testRevokeEndpointMarksRevokedAndAudits(): void
    {
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        $root = $this->tempRoot();
        $db = $this->createDb($root);

        $service = new ApiTokenService($db);
        $issued = $service->issueToken(1, 'CLI');

        $session = new InMemorySession();
        $session->start();
        $request = new Request('POST', '/api/v1/auth/revoke', [], [], [], '');
        $request->setSession($session);
        $request->setAttribute('api.user', ['id' => 1, 'status' => 1]);
        $request->setAttribute('api.token', (new ApiTokensRepository($db->pdo()))->findById((int) $issued['token_id']));

        $controller = new AuthController($db);
        $response = $controller->revoke($request);

        $this->assertSame(200, $response->getStatus());

        $row = (new ApiTokensRepository($db->pdo()))->findById((int) $issued['token_id']);
        $this->assertNotNull($row);
        $this->assertNotEmpty($row['revoked_at']);

        $auditRow = $this->fetchAuditRow($db);
        $this->assertSame('api.token.revoked', $auditRow['action']);
        $this->assertSame((int) $issued['token_id'], (int) $auditRow['entity_id']);
    }

    public function testTokenCreationAudited(): void
    {
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        $root = $this->tempRoot();
        $db = $this->createDb($root);
        $prevMode = getenv('API_TOKEN_ISSUE_MODE');
        putenv('API_TOKEN_ISSUE_MODE=admin_or_password');
        $_ENV['API_TOKEN_ISSUE_MODE'] = 'admin_or_password';

        $controller = new AuthController($db);
        $payload = json_encode([
            'name' => 'CLI',
            'username' => 'admin',
            'password' => 'secret',
        ], JSON_THROW_ON_ERROR);

        $request = new Request('POST', '/api/v1/auth/token', [], [], [
            'content-type' => 'application/json',
        ], (string) $payload);

        $response = $controller->token($request);
        $this->assertSame(201, $response->getStatus());

        $auditRow = $this->fetchAuditRow($db);
        $this->assertSame('api.token.created', $auditRow['action']);
        $this->assertStringContainsString('CLI', (string) $auditRow['context']);

        if ($prevMode === false) {
            putenv('API_TOKEN_ISSUE_MODE');
            unset($_ENV['API_TOKEN_ISSUE_MODE']);
        } else {
            putenv('API_TOKEN_ISSUE_MODE=' . $prevMode);
            $_ENV['API_TOKEN_ISSUE_MODE'] = $prevMode;
        }
    }

    public function testAuthFailedLoggedOncePerMinute(): void
    {
        $_SERVER['REMOTE_ADDR'] = '10.0.0.5';
        $root = $this->tempRoot();
        $db = $this->createDb($root);

        $middleware = new ApiMiddleware($db, $this->authorizationWithApiAccess($db), [
            'enabled' => true,
            'cors' => ['enabled' => false],
        ], $root);

        $request = new Request('GET', '/api/v1/me', [], [], [], '');
        $middleware->process($request, static fn (Request $req): Response => new Response('OK', 200));
        $middleware->process($request, static fn (Request $req): Response => new Response('OK', 200));

        $pdo = $db->pdo();
        $count = (int) $pdo->query('SELECT COUNT(*) FROM audit_logs')->fetchColumn();
        $this->assertSame(1, $count);
    }

    private function createDb(string $rootPath): DatabaseManager
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        $pdo->exec('CREATE TABLE users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            username VARCHAR(50) NOT NULL UNIQUE,
            email VARCHAR(190) NULL UNIQUE,
            password_hash VARCHAR(255) NOT NULL,
            status TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            last_login_at DATETIME NULL,
            last_login_ip VARCHAR(45) NULL
        )');

        $pdo->exec('CREATE TABLE api_tokens (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INT NOT NULL,
            name TEXT NOT NULL,
            token_hash CHAR(64) NOT NULL UNIQUE,
            last_used_at DATETIME NULL,
            expires_at DATETIME NULL,
            revoked_at DATETIME NULL,
            created_at DATETIME NOT NULL
        )');

        $pdo->exec('CREATE TABLE roles (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name VARCHAR(100) UNIQUE,
            title VARCHAR(150),
            created_at DATETIME,
            updated_at DATETIME
        )');

        $pdo->exec('CREATE TABLE permissions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name VARCHAR(100) UNIQUE,
            title VARCHAR(150),
            created_at DATETIME,
            updated_at DATETIME
        )');

        $pdo->exec('CREATE TABLE role_user (
            user_id INT NOT NULL,
            role_id INT NOT NULL
        )');

        $pdo->exec('CREATE TABLE permission_role (
            role_id INT NOT NULL,
            permission_id INT NOT NULL
        )');

        $pdo->exec('CREATE TABLE audit_logs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INT NULL,
            action VARCHAR(100) NOT NULL,
            entity VARCHAR(100) NOT NULL,
            entity_id INT NULL,
            context TEXT NULL,
            ip_address VARCHAR(45) NULL,
            created_at DATETIME NOT NULL
        )');

        $pdo->exec("INSERT INTO users (id, username, email, password_hash, status, created_at, updated_at) VALUES
            (1, 'admin', 'admin@example.com', '" . password_hash('secret', PASSWORD_DEFAULT) . "', 1, '2026-01-01', '2026-01-01')
        ");
        $pdo->exec("INSERT INTO roles (id, name, title, created_at, updated_at) VALUES (1, 'admin', 'Admin', '2026-01-01', '2026-01-01')");
        $pdo->exec("INSERT INTO permissions (id, name, title, created_at, updated_at) VALUES (1, 'api.access', 'API access', '2026-01-01', '2026-01-01')");
        $pdo->exec("INSERT INTO role_user (user_id, role_id) VALUES (1, 1)");
        $pdo->exec("INSERT INTO permission_role (role_id, permission_id) VALUES (1, 1)");

        $db = new DatabaseManager(['driver' => 'mysql']);
        $ref = new ReflectionProperty($db, 'pdo');
        $ref->setAccessible(true);
        $ref->setValue($db, $pdo);

        if (!is_dir($rootPath)) {
            mkdir($rootPath, 0775, true);
        }

        return $db;
    }

    private function authorizationWithApiAccess(DatabaseManager $db): AuthorizationService
    {
        return new AuthorizationService(new RbacRepository($db->pdo()));
    }

    private function tempRoot(): string
    {
        return sys_get_temp_dir() . '/laas-api-' . uniqid();
    }

    private function fetchAuditRow(DatabaseManager $db): array
    {
        $stmt = $db->pdo()->query('SELECT * FROM audit_logs ORDER BY id DESC LIMIT 1');
        $row = $stmt !== false ? $stmt->fetch() : null;
        return is_array($row) ? $row : [];
    }
}
