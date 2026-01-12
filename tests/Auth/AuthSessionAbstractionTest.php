<?php
declare(strict_types=1);

require_once __DIR__ . '/../Security/Support/SecurityTestHelper.php';

use Laas\Auth\AuthService;
use Laas\Database\Repositories\UsersRepository;
use Laas\Session\SessionInterface;
use PHPUnit\Framework\TestCase;
use Tests\Security\Support\SecurityTestHelper;

final class AuthSessionAbstractionTest extends TestCase
{
    public function testAuthRegeneratesSessionIdViaInterface(): void
    {
        $pdo = SecurityTestHelper::createSqlitePdo();
        if (method_exists($pdo, 'sqliteCreateFunction')) {
            $pdo->sqliteCreateFunction('NOW', static fn(): string => '2026-01-01 00:00:00');
        }
        SecurityTestHelper::seedRbacTables($pdo);
        SecurityTestHelper::insertUser($pdo, 1, 'admin', password_hash('secret', PASSWORD_DEFAULT));

        $session = new class implements SessionInterface {
            public bool $started = false;
            public int $regenerateIdCalls = 0;
            public ?bool $lastDeleteOld = null;
            private array $data = [];

            public function start(): void
            {
                $this->started = true;
            }

            public function isStarted(): bool
            {
                return $this->started;
            }

            public function regenerateId(bool $deleteOld = true): void
            {
                if (!$this->started) {
                    return;
                }
                $this->regenerateIdCalls++;
                $this->lastDeleteOld = $deleteOld;
            }

            public function get(string $key, mixed $default = null): mixed
            {
                return $this->data[$key] ?? $default;
            }

            public function set(string $key, mixed $value): void
            {
                $this->data[$key] = $value;
            }

            public function has(string $key): bool
            {
                return array_key_exists($key, $this->data);
            }

            public function delete(string $key): void
            {
                unset($this->data[$key]);
            }

            public function all(): array
            {
                return array_merge([], $this->data);
            }

            public function clear(): void
            {
                $this->data = [];
            }
        };
        $session->start();

        $auth = new AuthService(new UsersRepository($pdo), $session);
        $result = $auth->attempt('admin', 'secret', '127.0.0.1');

        $this->assertTrue($result);
        $this->assertSame(1, $session->regenerateIdCalls);
        $this->assertSame(true, $session->lastDeleteOld);
    }
}
