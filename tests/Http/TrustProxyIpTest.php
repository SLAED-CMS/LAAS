<?php
declare(strict_types=1);

use Laas\Http\Request;
use PHPUnit\Framework\TestCase;

final class TrustProxyIpTest extends TestCase
{
    private array $envBackup = [];
    private array $serverBackup = [];

    protected function setUp(): void
    {
        $this->envBackup = $_ENV;
        $this->serverBackup = $_SERVER;
    }

    protected function tearDown(): void
    {
        $_ENV = $this->envBackup;
        $_SERVER = $this->serverBackup;
    }

    public function testDisabledUsesRemoteAddr(): void
    {
        $_ENV['TRUST_PROXY_ENABLED'] = 'false';
        $_SERVER['REMOTE_ADDR'] = '10.0.0.1';

        $request = new Request('GET', '/', [], [], ['x-forwarded-for' => '8.8.8.8'], '');

        $this->assertSame('10.0.0.1', $request->ip());
    }

    public function testTrustedProxyUsesXff(): void
    {
        $_ENV['TRUST_PROXY_ENABLED'] = 'true';
        $_ENV['TRUST_PROXY_IPS'] = '10.0.0.1';
        $_ENV['TRUST_PROXY_HEADERS'] = 'x-forwarded-for,x-forwarded-proto';
        $_SERVER['REMOTE_ADDR'] = '10.0.0.1';

        $request = new Request('GET', '/', [], [], [
            'x-forwarded-for' => '10.0.0.2, 172.16.0.1, 8.8.8.8',
        ], '');

        $this->assertSame('8.8.8.8', $request->ip());
    }
}
