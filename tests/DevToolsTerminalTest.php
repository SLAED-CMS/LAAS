<?php
declare(strict_types=1);

use Laas\DevTools\DevToolsContext;
use PHPUnit\Framework\TestCase;

final class DevToolsTerminalTest extends TestCase
{
    public function testPromptLineFormat(): void
    {
        $ctx = new DevToolsContext([
            'enabled' => true,
            'budgets' => [
                'total_time_warn' => 200,
                'total_time_bad' => 500,
                'slow_sql_warn' => 50,
                'slow_sql_bad' => 200,
                'slow_http_warn' => 200,
                'slow_http_bad' => 1000,
            ],
        ]);
        $ctx->setRequest([
            'method' => 'GET',
            'path' => '/devtools',
            'get' => [],
            'get_raw' => '',
            'post' => [],
            'post_raw' => '',
            'cookies' => [],
            'headers' => [],
        ]);
        $ctx->setResponse([
            'status' => 200,
            'content_type' => 'text/html',
        ]);
        $ctx->finalize();

        $profile = $ctx->toArray()['profile'];
        $this->assertStringContainsString('laas> GET /devtools', $profile['terminal']['prompt_line']);
    }

    public function testWarningsIncludeSlowHttp(): void
    {
        $ctx = new DevToolsContext([
            'enabled' => true,
            'budgets' => [
                'total_time_warn' => 200,
                'total_time_bad' => 500,
                'slow_sql_warn' => 50,
                'slow_sql_bad' => 200,
                'slow_http_warn' => 200,
                'slow_http_bad' => 1000,
            ],
        ]);
        $ctx->addExternalCall('GET', 'https://example.com/path', 200, 350.0);
        $ctx->finalize();

        $profile = $ctx->toArray()['profile'];
        $this->assertStringContainsString('Slow HTTP request - example.com/path', $profile['terminal']['warnings_line']);
    }
}
