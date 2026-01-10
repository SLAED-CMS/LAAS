<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class ThemeApiDocsSmokeTest extends TestCase
{
    public function testThemeApiDocExistsAndHasSections(): void
    {
        $path = dirname(__DIR__) . '/docs/THEME_API.md';
        $this->assertFileExists($path);

        $contents = (string) file_get_contents($path);
        $this->assertStringContainsString('Purpose / Goals', $contents);
        $this->assertStringContainsString('Theme Structure', $contents);
        $this->assertStringContainsString('Provided variables', $contents);
        $this->assertStringContainsString('UI Tokens contract', $contents);
        $this->assertStringContainsString('Slots & blocks', $contents);
        $this->assertStringContainsString('HTMX rules', $contents);
        $this->assertStringContainsString('Forbidden', $contents);
        $this->assertStringContainsString('Migration notes', $contents);
    }
}
