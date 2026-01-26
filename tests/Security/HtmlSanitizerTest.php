<?php
declare(strict_types=1);

use Laas\Security\ContentProfiles;
use Laas\Security\HtmlSanitizer;
use PHPUnit\Framework\TestCase;

final class HtmlSanitizerTest extends TestCase
{
    public function testRemovesScriptAndStyleForSafeProfiles(): void
    {
        $html = '<p>ok</p><script>alert(1)</script><style>body{}</style>';
        $sanitizer = new HtmlSanitizer();

        foreach ([ContentProfiles::EDITOR_SAFE_RICH, ContentProfiles::USER_PLAIN] as $profile) {
            $sanitized = $sanitizer->sanitize($html, $profile);
            $this->assertStringContainsString('<p>ok</p>', $sanitized);
            $this->assertStringNotContainsString('<script', strtolower($sanitized));
            $this->assertStringNotContainsString('<style', strtolower($sanitized));
        }
    }

    public function testRemovesEventHandlers(): void
    {
        $html = '<p onload="alert(1)">x</p><img src="/a.png" onerror="alert(2)" alt="x">';
        $sanitized = (new HtmlSanitizer())->sanitize($html, ContentProfiles::EDITOR_SAFE_RICH);

        $this->assertStringNotContainsString('onload', strtolower($sanitized));
        $this->assertStringNotContainsString('onerror', strtolower($sanitized));
    }

    public function testBlocksJavascriptInHrefAndSrc(): void
    {
        $html = '<a href="javascript:alert(1)">x</a><img src="javascript:alert(2)" alt="x">';
        $sanitized = (new HtmlSanitizer())->sanitize($html, ContentProfiles::EDITOR_SAFE_RICH);

        $this->assertStringContainsString('<a', $sanitized);
        $this->assertStringNotContainsString('href=', strtolower($sanitized));
        $this->assertStringContainsString('<img', $sanitized);
        $this->assertStringNotContainsString('src=', strtolower($sanitized));
    }

    public function testEditorSafeRichRemovesIframesWhenAllowlistEmpty(): void
    {
        $html = '<iframe src="https://example.com/embed"></iframe>';
        $sanitized = (new HtmlSanitizer())->sanitize($html, ContentProfiles::EDITOR_SAFE_RICH);

        $this->assertStringNotContainsString('<iframe', strtolower($sanitized));
    }

    public function testUserPlainStripsImagesAndTables(): void
    {
        $html = '<p>ok</p><img src="/a.png" alt="x"><table><tr><td>x</td></tr></table>';
        $sanitized = (new HtmlSanitizer())->sanitize($html, ContentProfiles::USER_PLAIN);

        $this->assertStringNotContainsString('<img', strtolower($sanitized));
        $this->assertStringNotContainsString('<table', strtolower($sanitized));
        $this->assertStringContainsString('ok', $sanitized);
    }

    public function testUnknownProfileFallsBackToLegacy(): void
    {
        $html = '<img src="/a.png" alt="x">';
        $sanitized = (new HtmlSanitizer())->sanitize($html, 'unknown_profile');
        $legacy = (new HtmlSanitizer())->sanitize($html, ContentProfiles::LEGACY);

        $this->assertSame($legacy, $sanitized);
        $this->assertStringContainsString('<img', $sanitized);
        $this->assertStringContainsString('src=', strtolower($sanitized));
    }
}
