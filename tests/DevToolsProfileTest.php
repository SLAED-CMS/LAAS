<?php
declare(strict_types=1);

use Laas\DevTools\BudgetClassifier;
use Laas\DevTools\DevToolsContext;
use PHPUnit\Framework\TestCase;

final class DevToolsProfileTest extends TestCase
{
    public function testProfileSummaryTopSlowAndDuplicates(): void
    {
        $context = new DevToolsContext([
            'enabled' => true,
            'debug' => true,
            'env' => 'dev',
            'collect_db' => true,
            'collect_request' => false,
            'collect_logs' => false,
            'request_id' => bin2hex(random_bytes(8)),
            'budgets' => [
                'slow_sql_warn' => 50,
                'slow_sql_bad' => 200,
                'total_time_warn' => 200,
                'total_time_bad' => 500,
            ],
        ]);

        $context->addDbQuery('SELECT * FROM users', 0, 60.0);
        $context->addDbQuery('SELECT * FROM users', 0, 70.0);
        $context->addDbQuery('SELECT * FROM posts', 0, 55.0);
        $context->addDbQuery('SELECT * FROM roles', 0, 10.0);
        $context->finalize();

        $profile = $context->toArray()['profile'] ?? [];
        $sql = $profile['sql'] ?? [];

        $this->assertSame(1, (int) ($sql['duplicates_count'] ?? 0));
        $slow = $sql['top3_slowest_queries'] ?? [];
        $this->assertCount(2, $slow);
        $this->assertSame('SELECT * FROM users', $slow[0]['sql_preview'] ?? '');
    }

    public function testBudgetClassifier(): void
    {
        $ok = BudgetClassifier::classify(100, 200, 500);
        $warn = BudgetClassifier::classify(300, 200, 500);
        $bad = BudgetClassifier::classify(600, 200, 500);

        $this->assertSame('ok', $ok['status']);
        $this->assertSame('warn', $warn['status']);
        $this->assertSame('bad', $bad['status']);
    }
}
