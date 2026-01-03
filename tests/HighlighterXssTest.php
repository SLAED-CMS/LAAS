<?php
declare(strict_types=1);

use Laas\Support\Search\Highlighter;
use PHPUnit\Framework\TestCase;

final class HighlighterXssTest extends TestCase
{
    public function testSnippetStripsTags(): void
    {
        $text = '<script>alert(1)</script>Hello <b>world</b>';
        $segments = Highlighter::snippet($text, 'world', 160);

        $joined = '';
        foreach ($segments as $segment) {
            $joined .= $segment['text'];
        }

        $this->assertStringNotContainsString('<', $joined);
        $this->assertStringNotContainsString('>', $joined);
        $this->assertStringContainsString('Hello world', $joined);
    }
}
