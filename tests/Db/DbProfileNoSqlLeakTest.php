<?php
declare(strict_types=1);

use Laas\DevTools\DevToolsContext;
use PHPUnit\Framework\TestCase;

final class DbProfileNoSqlLeakTest extends TestCase
{
    public function testSqlDetailsHiddenWhenStoreSqlDisabled(): void
    {
        $context = new DevToolsContext([
            'enabled' => true,
            'debug' => true,
            'env' => 'prod',
            'is_dev' => false,
            'store_sql' => false,
        ]);

        $context->addDbQuery('SELECT secret FROM users WHERE id = 1', 0, 1.2);
        $context->addDbQuery('SELECT secret FROM users WHERE id = 2', 0, 1.3);
        $context->finalize();

        $payload = $context->toArray();
        $db = $payload['db'] ?? [];

        $this->assertSame(2, (int) ($db['count'] ?? 0));
        $this->assertSame([], $db['queries'] ?? []);
        $this->assertNotEmpty($db['top_slow'] ?? []);
        foreach ($db['top_slow'] ?? [] as $row) {
            $this->assertArrayHasKey('fingerprint', $row);
        }

        foreach (['grouped', 'duplicates'] as $key) {
            $rows = $db[$key] ?? [];
            foreach ($rows as $row) {
                $this->assertArrayNotHasKey('sql', $row);
                $this->assertArrayNotHasKey('samples', $row);
                $this->assertArrayNotHasKey('trace', $row);
            }
        }
    }
}
