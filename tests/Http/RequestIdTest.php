<?php

declare(strict_types=1);

use Laas\Http\Request;
use Laas\Http\RequestId;
use PHPUnit\Framework\TestCase;

final class RequestIdTest extends TestCase
{
    public function testNormalizeAcceptsValidId(): void
    {
        $this->assertSame('abc_DEF-1234', RequestId::normalize('  abc_DEF-1234  '));
    }

    public function testNormalizeRejectsInvalidId(): void
    {
        $this->assertNull(RequestId::normalize('short'));
        $this->assertNull(RequestId::normalize('bad*chars'));
    }

    public function testFromRequestUsesHeaderOrGenerates(): void
    {
        $request = new Request('GET', '/', [], [], ['x-request-id' => 'abc_DEF-1234'], '');
        $this->assertSame('abc_DEF-1234', RequestId::fromRequest($request));

        $request = new Request('GET', '/', [], [], [], '');
        $generated = RequestId::fromRequest($request);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $generated);
    }
}
