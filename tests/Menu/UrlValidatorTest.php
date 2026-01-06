<?php
declare(strict_types=1);

use Laas\Support\UrlValidator;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('security')]
final class UrlValidatorTest extends TestCase
{
    #[\PHPUnit\Framework\Attributes\DataProvider('unsafeControlCharProvider')]
    public function testRejectsControlChars(string $value): void
    {
        $this->assertFalse(UrlValidator::isSafe($value));
    }

    public function testAllowsHttp(): void
    {
        $this->assertTrue(UrlValidator::isSafe('http://example.com/path'));
    }

    public function testAllowsHttps(): void
    {
        $this->assertTrue(UrlValidator::isSafe('https://example.com/path'));
    }

    public function testAllowsRelativePath(): void
    {
        $this->assertTrue(UrlValidator::isSafe('/path/to/page'));
    }

    public function testRejectsProtocolRelative(): void
    {
        $this->assertFalse(UrlValidator::isSafe('//evil.example/path'));
    }

    public function testRejectsJavascriptScheme(): void
    {
        $this->assertFalse(UrlValidator::isSafe('javascript:alert(1)'));
    }

    public function testRejectsDataScheme(): void
    {
        $this->assertFalse(UrlValidator::isSafe('data:text/html;base64,PHNjcmlwdD5hbGVydCgxKTwvc2NyaXB0Pg=='));
    }

    public function testRejectsVbscriptScheme(): void
    {
        $this->assertFalse(UrlValidator::isSafe('vbscript:msgbox(\"x\")'));
    }

    public static function unsafeControlCharProvider(): array
    {
        return [
            ["java\nscript:alert(1)"],
            ["java\rscript:alert(1)"],
            ["java\tscript:alert(1)"],
            ["\0javascript:alert(1)"],
            [" \n javascript:alert(1)"],
            ["jav\x00ascript:alert(1)"],
            ["java\x1Fscript:alert(1)"],
            ["java\x7Fscript:alert(1)"],
        ];
    }
}
