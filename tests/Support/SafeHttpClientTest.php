<?php
declare(strict_types=1);

namespace Tests\Support;

use Laas\Support\SafeHttpClient;
use Laas\Support\UrlPolicy;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class SafeHttpClientTest extends TestCase
{
    public function testRedirectToPrivateIpIsBlocked(): void
    {
        $policy = new UrlPolicy(
            allowedSchemes: ['http', 'https'],
            allowedHostSuffixes: ['example.com'],
            allowPrivateIps: false,
            allowIpLiteral: true,
            resolver: static fn(string $host): array => ['93.184.216.34']
        );

        $sender = static function (string $method, string $url, array $headers, ?string $body, array $options): array {
            return [
                'status' => 302,
                'headers' => ['location' => 'http://127.0.0.1/'],
                'body' => '',
            ];
        };

        $client = new SafeHttpClient($policy, sender: $sender);

        $this->expectException(RuntimeException::class);
        $client->request('GET', 'https://example.com/resource');
    }
}
