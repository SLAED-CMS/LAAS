<?php
declare(strict_types=1);

use Laas\Support\ReleaseNotesExtractor;
use PHPUnit\Framework\TestCase;

final class ReleaseNotesExtractorTest extends TestCase
{
    public function testExtractsSectionByTag(): void
    {
        $content = <<<MD
# LAAS Versions

- v1.12.0: CI / QA
  - CI workflows
  - ops:check command
- v1.11.2: Backup
  - Inspect command
MD;

        $extractor = new ReleaseNotesExtractor();
        $notes = $extractor->extract($content, 'v1.12.0');

        $this->assertNotNull($notes);
        $this->assertStringContainsString('v1.12.0', $notes);
        $this->assertStringContainsString('CI workflows', $notes);
        $this->assertStringNotContainsString('v1.11.2', $notes);
    }

    public function testMissingTagReturnsNull(): void
    {
        $content = "- v1.0.0: Init\n  - First\n";
        $extractor = new ReleaseNotesExtractor();
        $this->assertNull($extractor->extract($content, 'v2.0.0'));
    }
}
