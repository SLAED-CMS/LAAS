<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class DocsTokensSmokeTest extends TestCase
{
    public function testUiTokensDocHasRequiredSections(): void
    {
        $root = dirname(__DIR__);
        $path = $root . '/docs/UI_TOKENS.md';

        $this->assertFileExists($path);

        $contents = (string) file_get_contents($path);

        $this->assertStringContainsString('## Purpose / Goals', $contents);
        $this->assertStringContainsString('## Token categories', $contents);
        $this->assertStringContainsString('## Naming rules', $contents);
        $this->assertStringContainsString('## Examples', $contents);
        $this->assertStringContainsString('## Anti-patterns', $contents);
        $this->assertStringContainsString('## Migration guide', $contents);
    }
}
