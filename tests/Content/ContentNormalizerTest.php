<?php
declare(strict_types=1);

use Laas\Content\ContentNormalizer;
use Laas\Content\MarkdownRenderer;
use Laas\Security\HtmlSanitizer;
use PHPUnit\Framework\TestCase;

final class ContentNormalizerTest extends TestCase
{
    public function testMarkdownBecomesHtmlAndIsSanitized(): void
    {
        $normalizer = new ContentNormalizer(new MarkdownRenderer(), new HtmlSanitizer());
        $input = '**Bold** [x](javascript:alert(1))';
        $output = $normalizer->normalize($input, 'markdown', 'editor_safe_rich');

        $this->assertStringContainsString('<strong>Bold</strong>', $output);
        $this->assertStringNotContainsString('javascript:', strtolower($output));
        $this->assertStringNotContainsString('href=', strtolower($output));
    }

    public function testHtmlPassesThroughSanitizer(): void
    {
        $normalizer = new ContentNormalizer(new MarkdownRenderer(), new HtmlSanitizer());
        $input = '<p onload="alert(1)">ok</p><script>alert(2)</script>';
        $output = $normalizer->normalize($input, 'html', 'user_plain');

        $lower = strtolower($output);
        $this->assertStringContainsString('<p>ok</p>', $output);
        $this->assertStringNotContainsString('script', $lower);
        $this->assertStringNotContainsString('onload', $lower);
    }
}
