<?php
declare(strict_types=1);

use Laas\Http\Request;
use PHPUnit\Framework\TestCase;

final class NegotiationTest extends TestCase
{
    public function testWantsJsonByAcceptHeader(): void
    {
        $request = new Request('GET', '/page', [], [], ['accept' => 'application/json'], '');
        $this->assertTrue($request->wantsJson());
    }

    public function testWantsJsonByFormatQuery(): void
    {
        $request = new Request('GET', '/page', ['format' => 'json'], [], [], '');
        $this->assertTrue($request->wantsJson());
    }

    public function testWantsJsonFalseForHtml(): void
    {
        $request = new Request('GET', '/page', [], [], ['accept' => 'text/html'], '');
        $this->assertFalse($request->wantsJson());
    }
}
