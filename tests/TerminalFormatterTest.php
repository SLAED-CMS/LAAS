<?php
declare(strict_types=1);

use Laas\DevTools\TerminalFormatter;
use PHPUnit\Framework\TestCase;

final class TerminalFormatterTest extends TestCase
{
    public function testSummarySegmentFormat(): void
    {
        $segment = TerminalFormatter::formatSummarySegment('SQL', '1/1 d0 0.0ms');
        $this->assertSame('SQL 1/1 d0 0.0ms', $segment);
    }

    public function testOffenderLineFormat(): void
    {
        $line = TerminalFormatter::formatOffenderLine('!', 'SQLD', 'select 1', 'x2');

        $this->assertStringStartsWith('! SQLD', $line);
        $this->assertStringContainsString('select 1', $line);
        $this->assertStringEndsWith('x2', $line);
    }
}
