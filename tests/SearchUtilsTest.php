<?php
declare(strict_types=1);

use Laas\Support\Search\Highlighter;
use Laas\Support\Search\LikeEscaper;
use Laas\Support\Search\SearchNormalizer;
use PHPUnit\Framework\TestCase;

final class SearchUtilsTest extends TestCase
{
    public function testLikeEscaperEscapesWildcards(): void
    {
        $value = 'a%_\\b';
        $this->assertSame('a\\%\\_\\\\b', LikeEscaper::escape($value));
    }

    public function testSearchNormalizerCollapsesSpaces(): void
    {
        $this->assertSame('foo bar', SearchNormalizer::normalize('  foo   bar  '));
    }

    public function testHighlighterSegmentsMarksMatch(): void
    {
        $segments = Highlighter::segments('Hello world', 'wo');
        $this->assertSame('Hello ', $segments[0]['text']);
        $this->assertFalse($segments[0]['mark']);
        $this->assertSame('wo', $segments[1]['text']);
        $this->assertTrue($segments[1]['mark']);
    }
}
