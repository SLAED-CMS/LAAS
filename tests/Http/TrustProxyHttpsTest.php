<?php
declare(strict_types=1);

use Laas\Http\Request;
use PHPUnit\Framework\TestCase;

final class TrustProxyHttpsTest extends TestCase
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

    public function testTrustedProxyUsesForwardedProto(): void
    {
        $_ENV['TRUST_PROXY_ENABLED'] = 'true';
        $_ENV['TRUST_PROXY_IPS'] = '10.0.0.1';
        $_ENV['TRUST_PROXY_HEADERS'] = 'x-forwarded-for,x-forwarded-proto';
        $_SERVER['REMOTE_ADDR'] = '10.0.0.1';
        $_SERVER['HTTPS'] = 'off';
        $_SERVER['SERVER_PORT'] = '80';

        $request = new Request('GET', '/', [], [], ['x-forwarded-proto' => 'https'], '');

        $this->assertTrue($request->isHttps());
    }
}
