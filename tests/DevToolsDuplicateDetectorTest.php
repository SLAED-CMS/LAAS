<?php
declare(strict_types=1);

use Laas\DevTools\DevToolsContext;
use PHPUnit\Framework\TestCase;

final class DevToolsDuplicateDetectorTest extends TestCase
{
    public function testDuplicateQueriesAreGrouped(): void
    {
        $context = new DevToolsContext([
            'enabled' => true,
            'debug' => true,
            'env' => 'dev',
            'collect_db' => true,
            'collect_request' => false,
            'collect_logs' => false,
            'request_id' => bin2hex(random_bytes(8)),
        ]);

        $context->addDbQuery('SELECT * FROM users WHERE id = :id LIMIT 1', 1, 2.5);
        $context->addDbQuery('SELECT   *  FROM users WHERE id = :id LIMIT 1', 1, 1.5);

        $db = $context->toArray()['db'] ?? [];
        $duplicates = $db['duplicates'] ?? [];

        $this->assertCount(1, $duplicates);
        $this->assertSame(2, (int) ($duplicates[0]['count'] ?? 0));
        $trace = $duplicates[0]['trace'] ?? [];
        $this->assertNotEmpty($trace);
        $this->assertIsArray($trace[0] ?? null);
        $this->assertArrayHasKey('call', $trace[0]);
        $this->assertArrayHasKey('file', $trace[0]);
    }
}
