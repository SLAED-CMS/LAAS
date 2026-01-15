<?php
declare(strict_types=1);

use Laas\Ai\Tools\ToolRegistry;
use PHPUnit\Framework\TestCase;

final class ToolRegistryTest extends TestCase
{
    public function testListUsesConfigAllowlist(): void
    {
        $registry = new ToolRegistry([
            'ai_plan_command_allowlist' => ['policy:check'],
        ]);

        $tools = $registry->list();
        $this->assertNotEmpty($tools);
        $this->assertSame('policy:check', $tools[0]['name'] ?? null);
    }
}
