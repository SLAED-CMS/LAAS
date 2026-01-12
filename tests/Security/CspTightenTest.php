<?php
declare(strict_types=1);

use Laas\Security\SecurityHeaders;
use PHPUnit\Framework\TestCase;

final class CspTightenTest extends TestCase
{
    private array $envBackup = [];

    protected function setUp(): void
    {
        $this->envBackup = $_ENV;
    }

    protected function tearDown(): void
    {
        $_ENV = $this->envBackup;
    }

    public function testCspDoesNotAllowCdnByDefault(): void
    {
        $_ENV['CSP_ALLOW_CDN'] = 'false';

        $config = require dirname(__DIR__, 2) . '/config/security.php';
        $headers = (new SecurityHeaders($config))->all();
        $csp = (string) ($headers['Content-Security-Policy'] ?? '');

        $this->assertStringNotContainsString('cdn.jsdelivr.net', $csp);
    }
}
