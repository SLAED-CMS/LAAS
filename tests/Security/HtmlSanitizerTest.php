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

    public function testEditorSafeRichAllowsImageAttributes(): void
    {
        $html = '<img src="/media/1/a.jpg" alt="a" title="t" width="120" height="80">';
        $sanitized = (new HtmlSanitizer())->sanitize($html, ContentProfiles::EDITOR_SAFE_RICH);

        $this->assertStringContainsString('src="/media/1/a.jpg"', $sanitized);
        $this->assertStringContainsString('alt="a"', $sanitized);
        $this->assertStringContainsString('title="t"', $sanitized);
        $this->assertStringContainsString('width="120"', $sanitized);
        $this->assertStringContainsString('height="80"', $sanitized);
    }

    public function testEditorSafeRichBlocksDataImageSrc(): void
    {
        $html = '<img src="data:image/png;base64,AAA" alt="x">';
        $sanitized = (new HtmlSanitizer())->sanitize($html, ContentProfiles::EDITOR_SAFE_RICH);

        $this->assertStringContainsString('<img', $sanitized);
        $this->assertStringNotContainsString('src=', strtolower($sanitized));
    }

    public function testUserPlainStripsImagesAndTables(): void
    {
        $html = '<p>ok</p><img src="/a.png" alt="x"><table><tr><td>x</td></tr></table>';
        $sanitized = (new HtmlSanitizer())->sanitize($html, ContentProfiles::USER_PLAIN);

        $this->assertStringNotContainsString('<img', strtolower($sanitized));
        $this->assertStringNotContainsString('<table', strtolower($sanitized));
        $this->assertStringContainsString('ok', $sanitized);
    }

    public function testUserPlainAddsRelTokensWhenMissing(): void
    {
        $html = '<a href="https://example.com">x</a>';
        $sanitized = (new HtmlSanitizer())->sanitize($html, ContentProfiles::USER_PLAIN);

        $this->assertStringContainsString('rel="nofollow ugc noopener"', $sanitized);
    }

    public function testUserPlainMergesExistingRelTokens(): void
    {
        $html = '<a href="https://example.com" rel="noreferrer">x</a>';
        $sanitized = (new HtmlSanitizer())->sanitize($html, ContentProfiles::USER_PLAIN);

        $this->assertStringContainsString('rel="nofollow ugc noopener noreferrer"', $sanitized);
    }

    public function testUserPlainKeepsTargetAndEnforcesRel(): void
    {
        $html = '<a href="https://example.com" target="_blank">x</a>';
        $sanitized = (new HtmlSanitizer())->sanitize($html, ContentProfiles::USER_PLAIN);

        $this->assertStringContainsString('target="_blank"', $sanitized);
        $this->assertStringContainsString('rel="nofollow ugc noopener"', $sanitized);
    }

    public function testUserPlainBlocksJavascriptHref(): void
    {
        $html = '<a href="javascript:alert(1)">x</a>';
        $sanitized = (new HtmlSanitizer())->sanitize($html, ContentProfiles::USER_PLAIN);

        $this->assertStringNotContainsString('href=', strtolower($sanitized));
    }

    public function testUserPlainBlocksDataHref(): void
    {
        $html = '<a href="data:text/html;base64,PHNjcmlwdD5hbGVydCgxKTwvc2NyaXB0Pg==">x</a>';
        $sanitized = (new HtmlSanitizer())->sanitize($html, ContentProfiles::USER_PLAIN);

        $this->assertStringNotContainsString('href=', strtolower($sanitized));
    }

    public function testUserPlainKeepsAllowedSchemes(): void
    {
        $html = '<a href="https://example.com">x</a><a href="http://example.com">y</a>'
            . '<a href="mailto:test@example.com">m</a><a href="tel:+123">t</a>';
        $sanitized = (new HtmlSanitizer())->sanitize($html, ContentProfiles::USER_PLAIN);

        $this->assertStringContainsString('href="https://example.com"', $sanitized);
        $this->assertStringContainsString('href="http://example.com"', $sanitized);
        $this->assertStringContainsString('href="mailto:test@example.com"', $sanitized);
        $this->assertStringContainsString('href="tel:+123"', $sanitized);
    }

    public function testUserPlainKeepsRelativeUrls(): void
    {
        $html = '<a href="/path">a</a><a href="./x">b</a><a href="../x">c</a>'
            . '<a href="#anchor">d</a><a href="?q=1">e</a>';
        $sanitized = (new HtmlSanitizer())->sanitize($html, ContentProfiles::USER_PLAIN);

        $this->assertStringContainsString('href="/path"', $sanitized);
        $this->assertStringContainsString('href="./x"', $sanitized);
        $this->assertStringContainsString('href="../x"', $sanitized);
        $this->assertStringContainsString('href="#anchor"', $sanitized);
        $this->assertStringContainsString('href="?q=1"', $sanitized);
    }

    public function testUserPlainBlocksObfuscatedJavascript(): void
    {
        $html = '<a href=" JaVaScRiPt:alert(1)">x</a><a href="java' . "\n" . 'script:alert(1)">y</a>';
        $sanitized = (new HtmlSanitizer())->sanitize($html, ContentProfiles::USER_PLAIN);

        $this->assertStringNotContainsString('href=', strtolower($sanitized));
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
