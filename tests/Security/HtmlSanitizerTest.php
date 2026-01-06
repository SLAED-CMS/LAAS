<?php
declare(strict_types=1);

use Laas\Security\HtmlSanitizer;
use PHPUnit\Framework\TestCase;

final class HtmlSanitizerTest extends TestCase
{
    public function testRemovesScriptTag(): void
    {
        $html = '<p>ok</p><script>alert(1)</script>';
        $sanitized = (new HtmlSanitizer())->sanitize($html);

        $this->assertStringContainsString('<p>ok</p>', $sanitized);
        $this->assertStringNotContainsString('script', strtolower($sanitized));
        $this->assertStringNotContainsString('alert(1)', $sanitized);
    }

    public function testRemovesEventHandlers(): void
    {
        $html = '<p onclick="alert(1)">x</p><img src="/a.png" onerror="alert(2)" alt="x">';
        $sanitized = (new HtmlSanitizer())->sanitize($html);

        $this->assertStringNotContainsString('onclick', strtolower($sanitized));
        $this->assertStringNotContainsString('onerror', strtolower($sanitized));
    }

    public function testBlocksJavascriptHref(): void
    {
        $html = '<a href="javascript:alert(1)">x</a>';
        $sanitized = (new HtmlSanitizer())->sanitize($html);

        $this->assertStringContainsString('<a', $sanitized);
        $this->assertStringNotContainsString('href=', strtolower($sanitized));
    }

    public function testBlocksDataSrc(): void
    {
        $html = '<img src="data:text/html;base64,AAA" alt="x">';
        $sanitized = (new HtmlSanitizer())->sanitize($html);

        $this->assertStringContainsString('<img', $sanitized);
        $this->assertStringNotContainsString('src=', strtolower($sanitized));
    }

    public function testUnwrapsUnknownTags(): void
    {
        $html = '<div><strong>Hi</strong><span>there</span></div>';
        $sanitized = (new HtmlSanitizer())->sanitize($html);

        $this->assertStringContainsString('<strong>Hi</strong>', $sanitized);
        $this->assertStringContainsString('there', $sanitized);
        $this->assertStringNotContainsString('<div', strtolower($sanitized));
        $this->assertStringNotContainsString('<span', strtolower($sanitized));
    }
}
