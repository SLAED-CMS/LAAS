<?php
declare(strict_types=1);

use Laas\DevTools\CompactFormatter;
use Laas\DevTools\DevToolsContext;
use PHPUnit\Framework\TestCase;

final class DevToolsCompactTest extends TestCase
{
    public function testCompactLineFormat(): void
    {
        $line = CompactFormatter::formatOffenderLine('!', 'SQLD', 'd3', 'SELECT * FROM t');
        $this->assertSame('! SQLD       d3  SELECT * FROM t', $line);
    }

    public function testOffendersSortedBySeverityAndScore(): void
    {
        $flags = [
            'enabled' => true,
            'budgets' => [
                'total_time_warn' => 10000,
                'total_time_bad' => 20000,
                'slow_sql_warn' => 50,
                'slow_sql_bad' => 200,
                'slow_http_warn' => 200,
                'slow_http_bad' => 1000,
            ],
        ];
        $ctx = new DevToolsContext($flags);
        $ctx->addDbQuery('SELECT * FROM users', 0, 60.0);
        $ctx->addDbQuery('SELECT * FROM users', 0, 60.0);
        $ctx->addExternalCall('GET', 'https://example.com/path', 200, 250.0);
        $ctx->finalize();

        $profile = $ctx->toArray()['profile'];
        $offenders = $profile['compact']['offenders'];

        $this->assertSame('HTTP', $offenders[0]['type']);
        $this->assertSame('SQL', $offenders[1]['type']);
        $this->assertSame('SQLD', $offenders[2]['type']);
    }
}
