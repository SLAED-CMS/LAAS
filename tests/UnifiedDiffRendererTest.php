<?php
declare(strict_types=1);

use Laas\Ai\Diff\UnifiedDiffRenderer;
use PHPUnit\Framework\TestCase;

final class UnifiedDiffRendererTest extends TestCase
{
    public function testCreateDiffIncludesAddedLines(): void
    {
        $renderer = new UnifiedDiffRenderer();
        $blocks = $renderer->render([
            [
                'op' => 'create',
                'path' => 'docs/demo.txt',
                'content' => "a\nb",
            ],
        ]);

        $this->assertCount(1, $blocks);
        $diff = $blocks[0]['diff'] ?? '';
        $this->assertStringContainsString('+a', $diff);
        $this->assertStringContainsString('+b', $diff);
        $this->assertSame(2, $blocks[0]['stats']['added'] ?? null);
        $this->assertSame(0, $blocks[0]['stats']['removed'] ?? null);
    }

    public function testUpdateDiffIncludesBeforeAfter(): void
    {
        $renderer = new UnifiedDiffRenderer();
        $blocks = $renderer->render([
            [
                'op' => 'update',
                'path' => 'docs/demo.txt',
                'before' => "old",
                'after' => "new",
            ],
        ]);

        $this->assertCount(1, $blocks);
        $diff = $blocks[0]['diff'] ?? '';
        $this->assertStringContainsString('-old', $diff);
        $this->assertStringContainsString('+new', $diff);
    }
}
