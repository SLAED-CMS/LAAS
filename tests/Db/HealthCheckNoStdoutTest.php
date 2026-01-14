<?php
declare(strict_types=1);

use Laas\Database\DatabaseManager;
use Laas\Support\RequestScope;
use PHPUnit\Framework\TestCase;

final class HealthCheckNoStdoutTest extends TestCase
{
    public function testHealthCheckNoStdoutWhenDriverMissing(): void
    {
        RequestScope::reset();
        $db = new DatabaseManager([
            'driver' => 'missing_driver',
            'host' => '127.0.0.1',
            'database' => 'missing',
        ]);

        ob_start();
        $result = $db->healthCheck();
        $out = ob_get_clean();

        $this->assertFalse($result);
        $this->assertSame('', $out);
    }
}
