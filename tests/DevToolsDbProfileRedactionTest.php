<?php
declare(strict_types=1);

use Laas\DevTools\DevToolsContext;
use PHPUnit\Framework\TestCase;

final class DevToolsDbProfileRedactionTest extends TestCase
{
    public function testFingerprintShownWhenRawSqlDisabled(): void
    {
        $context = new DevToolsContext([
            'enabled' => true,
            'debug' => true,
            'env' => 'dev',
            'collect_db' => true,
            'collect_request' => false,
            'collect_logs' => false,
            'store_sql' => true,
            'request_id' => bin2hex(random_bytes(8)),
        ]);

        $context->addDbQuery("SELECT * FROM users WHERE id = 123 AND email = 'a@example.com'", 0, 10.0);
        $context->addDbQuery("SELECT * FROM users WHERE id = 456 AND email = 'b@example.com'", 0, 12.0);

        $db = $context->toArray()['db'] ?? [];
        $topSlow = $db['top_slow'][0] ?? [];

        $this->assertStringContainsString('?', (string) ($topSlow['fingerprint'] ?? ''));
        $grouped = $db['grouped'][0] ?? [];
        $this->assertArrayNotHasKey('sql', $grouped);
        $this->assertNotEmpty($grouped['fingerprint'] ?? null);
    }
}
