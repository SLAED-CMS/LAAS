<?php
declare(strict_types=1);

use Laas\Content\Blocks\BlockRegistry;
use PHPUnit\Framework\TestCase;

final class JsonRenderTest extends TestCase
{
    public function testJsonRenderReturnsStructuredBlocks(): void
    {
        $registry = BlockRegistry::default();
        $blocks = $registry->normalizeBlocks([
            ['type' => 'rich_text', 'data' => ['html' => '<p>Hello</p><script>alert(1)</script>']],
        ]);

        $json = $registry->renderJsonBlocks($blocks);
        $this->assertSame('rich_text', $json[0]['type'] ?? null);
        $this->assertStringNotContainsString('<script>', $json[0]['data']['html'] ?? '');
    }
}
