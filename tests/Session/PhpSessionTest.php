<?php
declare(strict_types=1);

use Laas\Session\PhpSession;
use PHPUnit\Framework\TestCase;

final class PhpSessionTest extends TestCase
{
    protected function tearDown(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_unset();
            session_destroy();
        }
    }

    public function testSetGetHasRemoveClear(): void
    {
        $session = new PhpSession();
        $session->start();
        $session->clear();

        $this->assertTrue($session->isStarted());
        $this->assertFalse($session->has('foo'));

        $session->set('foo', 'bar');
        $this->assertTrue($session->has('foo'));
        $this->assertSame('bar', $session->get('foo'));

        $session->remove('foo');
        $this->assertFalse($session->has('foo'));

        $session->set('a', 1);
        $session->set('b', 2);
        $this->assertSame(['a' => 1, 'b' => 2], $session->all());

        $session->clear();
        $this->assertSame([], $session->all());
    }

    public function testRegenerateReturnsBool(): void
    {
        $session = new PhpSession();
        $session->start();

        $this->assertTrue($session->regenerate(true));
    }
}
