<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class AdminJsToastDedupeSmokeTest extends TestCase
{
    public function testAdminJsContainsToastDedupeAndLimits(): void
    {
        $root = dirname(__DIR__, 2);
        $script = file_get_contents($root . '/public/assets/admin.js');

        $this->assertNotFalse($script);
        $this->assertStringContainsString('laas:toast', $script);
        $this->assertMatchesRegularExpression('/toastDedupeWindowMs\s*=\s*2000/', $script);
        $this->assertMatchesRegularExpression('/toastQueueLimit\s*=\s*5/', $script);
        $this->assertStringContainsString('htmx:afterOnLoad', $script);
    }
}
