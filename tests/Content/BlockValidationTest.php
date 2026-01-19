<?php
declare(strict_types=1);

use Laas\Content\Blocks\BlockRegistry;
use Laas\Content\Blocks\BlockValidationException;
use PHPUnit\Framework\TestCase;

final class BlockValidationTest extends TestCase
{
    public function testInvalidRichTextFails(): void
    {
        $registry = BlockRegistry::default();

        $this->expectException(BlockValidationException::class);
        $registry->normalizeBlocks([
            ['type' => 'rich_text', 'data' => ['html' => []]],
        ]);
    }

    public function testInvalidImageFails(): void
    {
        $registry = BlockRegistry::default();

        $this->expectException(BlockValidationException::class);
        $registry->normalizeBlocks([
            ['type' => 'image', 'data' => ['media_id' => 0]],
        ]);
    }

    public function testInvalidCtaFails(): void
    {
        $registry = BlockRegistry::default();

        $this->expectException(BlockValidationException::class);
        $registry->normalizeBlocks([
            ['type' => 'cta', 'data' => ['label' => 'Click', 'url' => 'javascript:alert(1)']],
        ]);
    }
}
