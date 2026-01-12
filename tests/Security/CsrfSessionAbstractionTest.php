<?php
declare(strict_types=1);

use Laas\Security\Csrf;
use Laas\Session\SessionInterface;
use PHPUnit\Framework\TestCase;

final class CsrfSessionAbstractionTest extends TestCase
{
    public function testCsrfUsesSessionInterface(): void
    {
        $session = new class implements SessionInterface {
            public bool $started = false;
            public bool $setCalled = false;
            public bool $getCalled = false;
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
            }

            public function get(string $key, mixed $default = null): mixed
            {
                $this->getCalled = true;
                return $this->data[$key] ?? $default;
            }

            public function set(string $key, mixed $value): void
            {
                $this->setCalled = true;
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
        $csrf = new Csrf($session);
        $token = $csrf->getToken();

        $this->assertNotSame('', $token);
        $this->assertTrue($session->setCalled);
        $this->assertTrue($session->getCalled);
        $this->assertSame($token, $session->get(Csrf::SESSION_KEY));
    }
}
