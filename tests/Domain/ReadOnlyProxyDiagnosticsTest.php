<?php
declare(strict_types=1);

use Laas\Domain\Support\ReadOnlyProxy;
use Laas\Http\RequestContext;
use PHPUnit\Framework\TestCase;

final class ReadOnlyProxyDiagnosticsTest extends TestCase
{
    private array $serverBackup = [];
    private array $envBackup = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->serverBackup = $_SERVER;
        $this->envBackup = $_ENV;
        ReadOnlyProxy::setLogger(null);
    }

    protected function tearDown(): void
    {
        RequestContext::resetForTests();
        $_SERVER = $this->serverBackup;
        $_ENV = $this->envBackup;
        ReadOnlyProxy::setLogger(null);
        parent::tearDown();
    }

    public function testDebugEmitsOncePerRequest(): void
    {
        $_ENV['APP_DEBUG'] = '1';
        RequestContext::resetForTests();
        RequestContext::setForTests('rid-1', '/x?y=1');

        $messages = [];
        ReadOnlyProxy::setLogger(function (string $message) use (&$messages): void {
            $messages[] = $message;
        });

        $service = new ReadOnlyProxyDiagnosticsWriteService();
        $proxy = ReadOnlyProxy::wrap($service, ReadOnlyProxyDiagnosticsTestInterface::class);

        try {
            $proxy->write();
        } catch (DomainException) {
        }

        try {
            $proxy->write();
        } catch (DomainException) {
        }

        $this->assertCount(1, $messages);
        $this->assertStringContainsString(
            '[ReadOnlyProxy] blocked mutation ' . ReadOnlyProxyDiagnosticsTestInterface::class . '::write',
            $messages[0]
        );
        $this->assertStringContainsString(' req=', $messages[0]);
        $this->assertStringContainsString(' path=', $messages[0]);

        RequestContext::resetForTests();
        RequestContext::setForTests('rid-2', '/y');

        try {
            $proxy->write();
        } catch (DomainException) {
        }

        $this->assertCount(2, $messages);
        $this->assertStringContainsString(' req=rid-2', $messages[1]);
        $this->assertStringContainsString(' path=/y', $messages[1]);
    }

    public function testNonDebugEmitsNoWarning(): void
    {
        $_ENV['APP_DEBUG'] = '0';
        $_SERVER['HTTP_X_REQUEST_ID'] = 'rid-2';
        $_SERVER['REQUEST_URI'] = '/y';

        $messages = [];
        ReadOnlyProxy::setLogger(function (string $message) use (&$messages): void {
            $messages[] = $message;
        });

        $service = new ReadOnlyProxyDiagnosticsWriteService();
        $proxy = ReadOnlyProxy::wrap($service, ReadOnlyProxyDiagnosticsTestInterface::class);

        try {
            $proxy->write();
        } catch (DomainException) {
        }

        $this->assertCount(0, $messages);
    }
}

interface ReadOnlyProxyDiagnosticsTestInterface
{
    public function read(): string;

    public function write(): void;
}

final class ReadOnlyProxyDiagnosticsWriteService implements ReadOnlyProxyDiagnosticsTestInterface
{
    public function read(): string
    {
        return 'ok';
    }

    /** @mutation */
    public function write(): void
    {
    }
}
