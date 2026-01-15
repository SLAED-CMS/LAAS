<?php
declare(strict_types=1);

use Laas\Modules\Media\Service\S3Storage;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('security')]
final class SsrfSecurityTest extends TestCase
{
    public function testS3EndpointHostNotAffectedByDiskPath(): void
    {
        $captured = [];
        $client = function (string $method, string $url, array $headers, ?string $body) use (&$captured): array {
            $captured[] = $url;
            return ['status' => 200, 'headers' => [], 'body' => ''];
        };

        $storage = new S3Storage([
            'endpoint' => 'https://s3.example.com',
            'region' => 'us-east-1',
            'bucket' => 'laas',
            'access_key' => 'key',
            'secret_key' => 'secret',
            'use_path_style' => true,
            'timeout_seconds' => 1,
            'verify_tls' => true,
            'resolver' => static fn(string $host): array => ['93.184.216.34'],
        ], $client);

        $storage->exists('uploads/2026/01/http://evil.tld/payload.txt');

        $this->assertNotEmpty($captured);
        $host = parse_url($captured[0], PHP_URL_HOST);
        $this->assertSame('s3.example.com', $host);
    }
}
