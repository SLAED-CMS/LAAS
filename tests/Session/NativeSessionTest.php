<?php
declare(strict_types=1);

use Laas\Session\NativeSession;
use PHPUnit\Framework\TestCase;

final class NativeSessionTest extends TestCase
{
    protected function setUp(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_unset();
            session_destroy();
        }
        session_id(bin2hex(random_bytes(16)));
    }

    protected function tearDown(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_unset();
            session_destroy();
        }
    }

    public function testSetGetHasDeleteAllClear(): void
    {
        $session = new NativeSession();
        $session->start();
        $session->clear();

        $this->assertTrue($session->isStarted());
        $this->assertFalse($session->has('foo'));

        $session->set('foo', 'bar');
        $this->assertTrue($session->has('foo'));
        $this->assertSame('bar', $session->get('foo'));

        $session->delete('foo');
        $this->assertFalse($session->has('foo'));

        $session->set('a', 1);
        $session->set('b', 2);
        $this->assertSame(['a' => 1, 'b' => 2], $session->all());

        $session->clear();
        $this->assertSame([], $session->all());
    }
}
