<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class VersionsEncodingTest extends TestCase
{
    public function testVersionsFileHasNoMojibake(): void
    {
        $path = dirname(__DIR__, 2) . '/docs/VERSIONS.md';
        $this->assertFileExists($path);

        $contents = file_get_contents($path);
        $this->assertNotFalse($contents);

        $this->assertStringNotContainsString("\xC3\xA2\xE2\x82\xAC\xE2\x80\x9D", $contents);
        $this->assertStringNotContainsString("\xC3\x83", $contents);
        $this->assertStringContainsString("## v4.0.55 \xE2\x80\x94", $contents);
    }
}
