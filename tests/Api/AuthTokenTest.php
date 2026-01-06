<?php
declare(strict_types=1);

use Laas\Api\ApiTokenService;
use Laas\Database\DatabaseManager;
use Laas\Database\Repositories\ApiTokensRepository;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('api')]
#[Group('security')]
final class AuthTokenTest extends TestCase
{
    public function testIssueTokenStoresHashOnly(): void
    {
        [$db, $pdo] = $this->createDb();

        $service = new ApiTokenService($db);
        $result = $service->issueToken(1, 'CLI');

        $this->assertNotEmpty($result['token']);
        $this->assertGreaterThan(0, $result['token_id']);

        $repo = new ApiTokensRepository($pdo);
        $row = $repo->findById((int) $result['token_id']);
        $this->assertNotNull($row);

        $this->assertSame(hash('sha256', $result['token']), (string) ($row['token_hash'] ?? ''));
        $this->assertNotSame($result['token'], (string) ($row['token_hash'] ?? ''));
    }

    public function testBearerAuthWorks(): void
    {
        [$db] = $this->createDb();

        $service = new ApiTokenService($db);
        $result = $service->issueToken(1, 'CLI');

        $auth = $service->authenticate($result['token']);
        $this->assertNotNull($auth);
        $this->assertSame(1, (int) ($auth['user']['id'] ?? 0));
        $this->assertSame((int) $result['token_id'], (int) ($auth['token']['id'] ?? 0));
    }

    public function testRevokeRemovesToken(): void
    {
        [$db] = $this->createDb();

        $service = new ApiTokenService($db);
        $result = $service->issueToken(1, 'CLI');

        $this->assertTrue($service->revoke((int) $result['token_id'], 1));
        $repo = new ApiTokensRepository($db->pdo());
        $row = $repo->findById((int) $result['token_id']);
        $this->assertNotNull($row);
        $this->assertNotEmpty($row['revoked_at']);
        $this->assertNull($service->authenticate($result['token']));
    }

    /** @return array{0: DatabaseManager, 1: PDO} */
    private function createDb(): array
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

        $pdo->exec("INSERT INTO users (username, email, password_hash, status, created_at, updated_at)
            VALUES ('admin', 'admin@example.com', '" . password_hash('secret', PASSWORD_DEFAULT) . "', 1, '2026-01-01', '2026-01-01')");

        $db = new DatabaseManager(['driver' => 'mysql']);
        $ref = new ReflectionProperty($db, 'pdo');
        $ref->setAccessible(true);
        $ref->setValue($db, $pdo);

        return [$db, $pdo];
    }
}
