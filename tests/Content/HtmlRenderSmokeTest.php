<?php
declare(strict_types=1);

use Laas\Content\Blocks\BlockRegistry;
use Laas\Content\Blocks\ThemeContext;
use PHPUnit\Framework\TestCase;

final class HtmlRenderSmokeTest extends TestCase
{
    public function testHtmlRenderUsesSanitizedOutput(): void
    {
        $registry = BlockRegistry::default();
        $blocks = $registry->normalizeBlocks([
            ['type' => 'rich_text', 'data' => ['html' => '<p>Hello</p><script>alert(1)</script>']],
            ['type' => 'cta', 'data' => ['label' => 'Go', 'url' => '/hello', 'style' => 'primary']],
        ]);

        $html = $registry->renderHtmlBlocks($blocks, new ThemeContext('default', 'en'));
        $this->assertCount(2, $html);
        $this->assertStringContainsString('block-richtext', (string) $html[0]);
        $this->assertStringNotContainsString('<script>', (string) $html[0]);
    }
}
