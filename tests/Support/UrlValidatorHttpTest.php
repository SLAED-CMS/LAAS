<?php
declare(strict_types=1);

namespace Tests\Support;

use Laas\Support\UrlPolicy;
use Laas\Support\UrlValidator;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class UrlValidatorHttpTest extends TestCase
{
    public function testBlocksPrivateAndLoopbackIps(): void
    {
        $policy = new UrlPolicy(
            allowedSchemes: ['http', 'https'],
            allowPrivateIps: false,
            allowIpLiteral: true
        );

        $blocked = [
            'http://127.0.0.1',
            'http://169.254.169.254',
            'http://10.0.0.1',
            'http://[::1]',
            'http://[::ffff:127.0.0.1]',
        ];

        foreach ($blocked as $url) {
            try {
                UrlValidator::assertSafeHttpUrl($url, $policy);
                $this->fail('Expected SSRF block: ' . $url);
            } catch (RuntimeException) {
                $this->assertTrue(true);
            }
        }
    }

    public function testBlocksLocalhostHostnames(): void
    {
        $policy = new UrlPolicy(allowedSchemes: ['http', 'https']);

        $this->expectException(RuntimeException::class);
        UrlValidator::assertSafeHttpUrl('http://localhost', $policy);
    }

    public function testAllowsGithubHostsWithAllowlist(): void
    {
        $policy = new UrlPolicy(
            allowedSchemes: ['https'],
            allowedHostSuffixes: ['api.github.com', 'github.com'],
            allowPrivateIps: false,
            allowIpLiteral: false,
            resolver: static fn(string $host): array => ['93.184.216.34']
        );

        UrlValidator::assertSafeHttpUrl('https://api.github.com/repos/org/repo/commits', $policy);
        UrlValidator::assertSafeHttpUrl('https://github.com/org/repo', $policy);
        $this->assertTrue(true);
    }

    public function testAllowsS3Allowlist(): void
    {
        $policy = new UrlPolicy(
            allowedSchemes: ['https'],
            allowedHostSuffixes: ['amazonaws.com'],
            allowPrivateIps: false,
            allowIpLiteral: false,
            resolver: static fn(string $host): array => ['93.184.216.34']
        );

        UrlValidator::assertSafeHttpUrl('https://s3.amazonaws.com/bucket/key', $policy);
        $this->assertTrue(true);
    }
}
