<?php
declare(strict_types=1);

use Laas\Content\Blocks\BlockRegistry;
use Laas\Content\Blocks\BlockValidationException;
use PHPUnit\Framework\TestCase;

final class BlockRegistryTest extends TestCase
{
    public function testRegistryRegistersCoreBlocks(): void
    {
        $registry = BlockRegistry::default();
        $this->assertTrue($registry->has('rich_text'));
        $this->assertTrue($registry->has('image'));
        $this->assertTrue($registry->has('cta'));
    }

    public function testUnknownTypeThrows(): void
    {
        $registry = BlockRegistry::default();

        $this->expectException(BlockValidationException::class);
        $registry->normalizeBlocks([
            ['type' => 'unknown', 'data' => []],
        ]);
    }
}
