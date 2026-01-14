<?php
declare(strict_types=1);

use Laas\DevTools\DevToolsContext;
use PHPUnit\Framework\TestCase;

final class DbProfileNoRawSqlInProdTest extends TestCase
{
    public function testRawSqlNeverStoredWhenDebugDisabled(): void
    {
        $context = new DevToolsContext([
            'enabled' => true,
            'debug' => false,
            'env' => 'prod',
            'is_dev' => false,
            'store_sql' => true,
            'raw_sql_allowed' => true,
        ]);

        $context->addDbQuery("SELECT secret FROM users WHERE id = 1", 0, 1.2);
        $context->addDbQuery("SELECT secret FROM users WHERE id = 2", 0, 1.3);
        $context->finalize();

        $db = $context->toArray()['db'] ?? [];

        $this->assertSame([], $db['queries'] ?? []);
        foreach (['grouped', 'duplicates'] as $key) {
            foreach (($db[$key] ?? []) as $row) {
                $this->assertArrayNotHasKey('sql', $row);
            }
        }

        $topSlow = $db['top_slow'] ?? [];
        $this->assertNotEmpty($topSlow);
        $this->assertStringContainsString('?', (string) ($topSlow[0]['fingerprint'] ?? ''));
    }
}
