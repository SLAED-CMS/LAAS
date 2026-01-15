<?php
declare(strict_types=1);

use Laas\Ai\Context\Redactor;
use Laas\Ai\Provider\RemoteHttpProvider;
use Laas\Support\SafeHttpClient;
use Laas\Support\UrlPolicy;
use PHPUnit\Framework\TestCase;

final class RemoteHttpProviderAllowlistTest extends TestCase
{
    public function testAllowlistMismatchThrows(): void
    {
        $client = new SafeHttpClient(new UrlPolicy(), 1, 1, 0, 1, static function (): array {
            return ['status' => 200, 'headers' => [], 'body' => '{}'];
        });

        $provider = new RemoteHttpProvider($client, new Redactor(), [
            'ai_remote_enabled' => true,
            'ai_remote_allowlist' => ['https://ai.example.com'],
            'ai_remote_base' => 'https://evil.example.com',
        ]);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('remote_ai_forbidden');
        $provider->propose(['prompt' => 'test']);
    }
}
