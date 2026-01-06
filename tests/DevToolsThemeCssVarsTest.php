<?php
declare(strict_types=1);

use Laas\DevTools\DevToolsContext;
use PHPUnit\Framework\TestCase;

final class DevToolsThemeCssVarsTest extends TestCase
{
    public function testCssVarsRendered(): void
    {
        $ctx = new DevToolsContext([
            'enabled' => true,
        ]);
        $ctx->finalize();

        $theme = $ctx->toArray()['theme']['terminal'];
        $vars = (string) ($theme['css_vars'] ?? '');

        $this->assertStringContainsString('--dt-bg:', $vars);
        $this->assertStringContainsString('--dt-font-size:16px', $vars);
        $this->assertStringContainsString('--dt-font-family:Verdana, Tahoma, monospace', $vars);
        $this->assertStringContainsString('--dt-line-height:1.25', $vars);
    }
}
