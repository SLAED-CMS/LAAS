<?php
declare(strict_types=1);

use Laas\Core\Kernel;
use Laas\Http\Request;
use PHPUnit\Framework\TestCase;

final class RequestIdHeaderTest extends TestCase
{
    public function testRequestIdHeaderPresent(): void
    {
        $root = dirname(__DIR__);
        $kernel = new Kernel($root);
        $response = $kernel->handle(new Request('GET', '/health', [], [], [], ''));

        $this->assertNotEmpty($response->getHeader('X-Request-Id'));
    }
}
