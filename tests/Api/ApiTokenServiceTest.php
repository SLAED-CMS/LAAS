<?php
declare(strict_types=1);

use Laas\Api\ApiTokenService;
use Laas\Database\DatabaseManager;
use Laas\Database\Repositories\ApiTokensRepository;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('api')]
#[Group('security')]
final class ApiTokenServiceTest extends TestCase
{
    public function testCreateTokenStoresScopesAndPrefix(): void
    {
        [$db, $pdo] = $this->createDb();

        $service = $this->createService($db);
        $result = $service->createToken(1, 'CLI', ['admin.read', 'media.read']);

        $this->assertStringStartsWith('LAAS_', $result['token']);
        $this->assertSame(12, strlen((string) ($result['token_prefix'] ?? '')));

        $repo = new ApiTokensRepository($pdo);
        $row = $repo->findById((int) $result['token_id']);
        $this->assertNotNull($row);

        $this->assertSame(hash('sha256', $result['token']), (string) ($row['token_hash'] ?? ''));
        $this->assertSame((string) ($result['token_prefix'] ?? ''), (string) ($row['token_prefix'] ?? ''));
        $this->assertNotSame($result['token'], (string) ($row['token_hash'] ?? ''));
        $storedScopes = json_decode((string) ($row['scopes'] ?? ''), true);
        $this->assertSame(['admin.read', 'media.read'], $storedScopes);
    }

    public function testVerifyTokenReturnsUserAndScopes(): void
    {
        [$db] = $this->createDb();

        $service = $this->createService($db);
        $result = $service->createToken(1, 'CLI', ['admin.read', 'media.read']);

        $verified = $service->verifyToken($result['token']);

        $this->assertNotNull($verified);
        $this->assertSame(1, (int) ($verified['user_id'] ?? 0));
        $this->assertSame(['admin.read', 'media.read'], $verified['scopes'] ?? []);
        $this->assertSame((int) $result['token_id'], (int) ($verified['token_id'] ?? 0));
    }

    public function testExpiredTokenRejected(): void
    {
        [$db] = $this->createDb();

        $service = $this->createService($db);
        $expiredAt = date('Y-m-d H:i:s', strtotime('-1 day'));
        $result = $service->createToken(1, 'CLI', ['admin.read'], $expiredAt);

        $this->assertNull($service->verifyToken($result['token']));
    }

    public function testRevokeTokenInvalidates(): void
    {
        [$db] = $this->createDb();

        $service = $this->createService($db);
        $result = $service->createToken(1, 'CLI', ['admin.read']);

        $this->assertTrue($service->revokeToken((int) $result['token_id'], 1));
        $this->assertNull($service->verifyToken($result['token']));
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
            token_prefix TEXT NOT NULL,
            scopes TEXT NULL,
            last_used_at DATETIME NULL,
            expires_at DATETIME NULL,
            revoked_at DATETIME NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL
        )');

        $pdo->exec("INSERT INTO users (username, email, password_hash, status, created_at, updated_at)
            VALUES ('admin', 'admin@example.com', '" . password_hash('secret', PASSWORD_DEFAULT) . "', 1, '2026-01-01', '2026-01-01')");

        $db = new DatabaseManager(['driver' => 'mysql']);
        $ref = new ReflectionProperty($db, 'pdo');
        $ref->setAccessible(true);
        $ref->setValue($db, $pdo);

        return [$db, $pdo];
    }

    private function createService(DatabaseManager $db): ApiTokenService
    {
        return new ApiTokenService($db, [
            'token_scopes' => ['admin.read', 'media.read'],
        ]);
    }
}
