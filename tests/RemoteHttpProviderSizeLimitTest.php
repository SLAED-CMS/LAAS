<?php
declare(strict_types=1);

use Laas\Ai\Context\Redactor;
use Laas\Ai\Provider\RemoteHttpProvider;
use Laas\Support\SafeHttpClient;
use Laas\Support\UrlPolicy;
use PHPUnit\Framework\TestCase;

final class RemoteHttpProviderSizeLimitTest extends TestCase
{
    public function testRequestTooLargeThrows(): void
    {
        $client = new SafeHttpClient(new UrlPolicy(), 1, 1, 0, 1, static function (): array {
            return ['status' => 200, 'headers' => [], 'body' => '{}'];
        });

        $provider = new RemoteHttpProvider($client, new Redactor(), [
            'ai_remote_enabled' => true,
            'ai_remote_allowlist' => ['https://ai.example.com'],
            'ai_remote_max_request_bytes' => 10,
        ]);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('remote_ai_request_too_large');
        $provider->propose(['prompt' => str_repeat('a', 200)]);
    }
}
