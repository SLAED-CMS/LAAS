<?php
declare(strict_types=1);

namespace Tests\Media;

use Laas\Modules\Media\Service\S3Storage;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @group security
 * @group ssrf
 */
final class S3StorageSsrfTest extends TestCase
{
    public function testRejectsPrivateIpEndpointsAwsMetadata(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('s3_endpoint_resolves_to_private_ip');

        new S3Storage([
            'endpoint' => 'http://169.254.169.254',
            'region' => 'us-east-1',
            'bucket' => 'test',
            'access_key' => 'key',
            'secret_key' => 'secret',
        ]);
    }

    public function testRejectsPrivateIpEndpoints10(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('s3_endpoint_resolves_to_private_ip');

        new S3Storage([
            'endpoint' => 'http://10.0.0.1',
            'region' => 'us-east-1',
            'bucket' => 'test',
            'access_key' => 'key',
            'secret_key' => 'secret',
        ]);
    }

    public function testRejectsPrivateIpEndpoints192(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('s3_endpoint_resolves_to_private_ip');

        new S3Storage([
            'endpoint' => 'http://192.168.1.1',
            'region' => 'us-east-1',
            'bucket' => 'test',
            'access_key' => 'key',
            'secret_key' => 'secret',
        ]);
    }

    public function testRejectsNonHttpsEndpoints(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('s3_endpoint_must_use_https');

        new S3Storage([
            'endpoint' => 'http://s3.amazonaws.com',
            'region' => 'us-east-1',
            'bucket' => 'test',
            'access_key' => 'key',
            'secret_key' => 'secret',
        ]);
    }

    public function testRejectsInvalidUrls(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('s3_endpoint_missing_host');

        new S3Storage([
            'endpoint' => 'not-a-url',
            'region' => 'us-east-1',
            'bucket' => 'test',
            'access_key' => 'key',
            'secret_key' => 'secret',
        ]);
    }

    public function testRejectsMissingHost(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('s3_endpoint_invalid_url');

        new S3Storage([
            'endpoint' => 'https://',
            'region' => 'us-east-1',
            'bucket' => 'test',
            'access_key' => 'key',
            'secret_key' => 'secret',
        ]);
    }

    public function testAllowsLegitimateHttpsEndpoints(): void
    {
        $storage = new S3Storage([
            'endpoint' => 'https://s3.amazonaws.com',
            'region' => 'us-east-1',
            'bucket' => 'test',
            'access_key' => 'key',
            'secret_key' => 'secret',
        ]);

        $this->assertSame('s3', $storage->name());
    }

    public function testAllowsLocalhostForDev(): void
    {
        $storage = new S3Storage([
            'endpoint' => 'http://localhost:9000',
            'region' => 'us-east-1',
            'bucket' => 'test',
            'access_key' => 'key',
            'secret_key' => 'secret',
        ]);

        $this->assertSame('s3', $storage->name());
    }

    public function testAllowsEmptyEndpoint(): void
    {
        $storage = new S3Storage([
            'endpoint' => '',
            'region' => 'us-east-1',
            'bucket' => 'test',
            'access_key' => 'key',
            'secret_key' => 'secret',
        ]);

        $this->assertSame('s3', $storage->name());
    }
}