<?php
declare(strict_types=1);

use Laas\Ops\Checks\SessionCheck;
use PHPUnit\Framework\TestCase;

final class SessionCheckTest extends TestCase
{
    public function testNativeDriverOk(): void
    {
        $check = new SessionCheck([
            'driver' => 'native',
        ]);

        $result = $check->run();
        $this->assertSame(0, $result['code']);
        $this->assertStringContainsString('native session: OK', $result['message']);
    }

    public function testRedisDriverWarnsWithoutSecrets(): void
    {
        $check = new SessionCheck([
            'driver' => 'redis',
            'redis' => [
                'url' => 'redis://user:pass@127.0.0.1:1/0',
                'timeout' => 0.01,
            ],
        ]);

        $result = $check->run();
        $this->assertSame(2, $result['code']);
        $this->assertStringContainsString('redis session: FAIL (fallback native)', $result['message']);
        $this->assertStringNotContainsString('user', $result['message']);
        $this->assertStringNotContainsString('pass', $result['message']);
    }
}
