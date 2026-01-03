<?php
declare(strict_types=1);

use Laas\Modules\Media\Service\S3Storage;
use PHPUnit\Framework\TestCase;

final class S3StorageTest extends TestCase
{
    public function testStorageContractWithMockClient(): void
    {
        $requests = [];
        $client = static function (
            string $method,
            string $url,
            array $headers,
            ?string $body,
            int $timeout,
            bool $verifyTls
        ) use (&$requests): array {
            $requests[] = [
                'method' => $method,
                'url' => $url,
                'headers' => $headers,
                'body' => $body,
                'timeout' => $timeout,
                'verify_tls' => $verifyTls,
            ];

            return match ($method) {
                'PUT' => ['status' => 200, 'headers' => [], 'body' => ''],
                'HEAD' => ['status' => 200, 'headers' => ['content-length' => '4'], 'body' => ''],
                'GET' => ['status' => 200, 'headers' => [], 'body' => 'data'],
                'DELETE' => ['status' => 204, 'headers' => [], 'body' => ''],
                default => ['status' => 500, 'headers' => [], 'body' => ''],
            };
        };

        $storage = new S3Storage([
            'endpoint' => 'http://127.0.0.1:9000',
            'region' => 'us-east-1',
            'bucket' => 'bucket',
            'access_key' => 'key',
            'secret_key' => 'secret',
            'use_path_style' => true,
            'prefix' => 'laas',
            'timeout_seconds' => 5,
            'verify_tls' => false,
        ], $client);

        $diskPath = 'uploads/2026/01/test.txt';

        $this->assertTrue($storage->putContents($diskPath, 'data'));
        $this->assertTrue($storage->exists($diskPath));
        $this->assertSame(4, $storage->size($diskPath));

        $stream = $storage->getStream($diskPath);
        $this->assertIsResource($stream);
        $this->assertSame('data', stream_get_contents($stream));
        if (is_resource($stream)) {
            fclose($stream);
        }

        $this->assertTrue($storage->delete($diskPath));

        $this->assertCount(5, $requests);
        $this->assertStringContainsString('/bucket/laas/uploads/2026/01/test.txt', $requests[0]['url']);
        $this->assertSame(5, $requests[0]['timeout']);
        $this->assertFalse($requests[0]['verify_tls']);
        $this->assertArrayHasKey('Authorization', $requests[0]['headers']);
    }

    public function testDiskPathDoesNotChangeHost(): void
    {
        $requests = [];
        $client = static function (
            string $method,
            string $url,
            array $headers,
            ?string $body,
            int $timeout,
            bool $verifyTls
        ) use (&$requests): array {
            $requests[] = $url;
            return ['status' => 200, 'headers' => [], 'body' => ''];
        };

        $storage = new S3Storage([
            'endpoint' => 'https://s3.local',
            'region' => 'us-east-1',
            'bucket' => 'bucket',
            'access_key' => 'key',
            'secret_key' => 'secret',
            'use_path_style' => true,
            'prefix' => '',
            'timeout_seconds' => 5,
            'verify_tls' => false,
        ], $client);

        $storage->exists('http://evil.example/steal');

        $this->assertNotEmpty($requests);
        $host = parse_url($requests[0], PHP_URL_HOST);
        $this->assertSame('s3.local', $host);
    }
}
