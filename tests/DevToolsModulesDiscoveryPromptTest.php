<?php

declare(strict_types=1);

use Laas\DevTools\DevToolsContext;
use PHPUnit\Framework\TestCase;

final class DevToolsModulesDiscoveryPromptTest extends TestCase
{
    public function testModulesStatsAddedToPrompt(): void
    {
        $context = new DevToolsContext([
            'enabled' => true,
            'debug' => true,
            'env' => 'dev',
            'budgets' => [],
        ]);
        $context->setModules([
            'total' => [
                'calls' => 3,
                'ms' => 12.34,
                'count' => 9,
            ],
        ]);
        $context->finalize();

        $data = $context->toArray();

        $this->assertSame(3, $data['profile']['terminal']['prompt']['modules_calls']);
        $this->assertSame(12.3, $data['profile']['terminal']['prompt']['modules_ms']);
        $this->assertSame(9, $data['modules']['total']['count']);
    }
}
