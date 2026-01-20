<?php
declare(strict_types=1);

use Laas\Domain\Support\ReadOnlyProxy;
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
        $_SERVER = $this->serverBackup;
        $_ENV = $this->envBackup;
        ReadOnlyProxy::setLogger(null);
        parent::tearDown();
    }

    public function testDebugEmitsOncePerRequest(): void
    {
        $_ENV['APP_DEBUG'] = '1';
        $_SERVER['HTTP_X_REQUEST_ID'] = 'rid-1';
        $_SERVER['REQUEST_URI'] = '/x?y=1';

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
        $this->assertSame(
            '[ReadOnlyProxy] blocked mutation ' . ReadOnlyProxyDiagnosticsTestInterface::class
            . '::write req=rid-1 path=/x',
            $messages[0]
        );
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
