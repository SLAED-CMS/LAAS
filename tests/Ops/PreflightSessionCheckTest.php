<?php
declare(strict_types=1);

use Laas\Ops\Checks\SessionCheck;
use PHPUnit\Framework\TestCase;

final class PreflightSessionCheckTest extends TestCase
{
    public function testSessionCheckWarnsOnTtlMismatch(): void
    {
        $root = sys_get_temp_dir() . '/laas_session_check_' . bin2hex(random_bytes(4));
        @mkdir($root . '/storage/sessions', 0775, true);

        $check = new SessionCheck([
            'driver' => 'native',
            'idle_ttl' => 10,
            'absolute_ttl' => 5,
        ], $root);

        $result = $check->run();
        $this->assertSame(2, $result['code']);
        $this->assertStringContainsString('session config: WARN', $result['message']);
    }
}
