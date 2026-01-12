<?php
declare(strict_types=1);

use Laas\Support\UrlSanitizer;
use PHPUnit\Framework\TestCase;

final class UrlSanitizerTest extends TestCase
{
    public function testSanitizeRedisUrlWithCredentials(): void
    {
        $url = 'redis://user:pass@host:6379/0';
        $this->assertSame('redis://***:***@host:6379/0', UrlSanitizer::sanitizeRedisUrl($url));
    }

    public function testSanitizeRedisUrlWithoutCredentials(): void
    {
        $url = 'redis://host:6379/0';
        $this->assertSame($url, UrlSanitizer::sanitizeRedisUrl($url));
    }
}
