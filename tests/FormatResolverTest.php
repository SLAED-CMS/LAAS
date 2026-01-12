<?php
declare(strict_types=1);

use Laas\Http\FormatResolver;
use Laas\Http\Request;
use PHPUnit\Framework\TestCase;

final class FormatResolverTest extends TestCase
{
    public function testResolvesJsonByFormatParam(): void
    {
        $resolver = new FormatResolver();
        $request = new Request('GET', '/page', ['format' => 'json'], [], [], '');

        $this->assertSame('json', $resolver->resolve($request));
    }

    public function testResolvesJsonByAcceptHeader(): void
    {
        $resolver = new FormatResolver();
        $request = new Request('GET', '/page', [], [], ['accept' => 'application/json'], '');

        $this->assertSame('json', $resolver->resolve($request));
    }

    public function testResolvesHtmlByDefault(): void
    {
        $resolver = new FormatResolver();
        $request = new Request('GET', '/page', [], [], ['accept' => 'text/html'], '');

        $this->assertSame('html', $resolver->resolve($request));
    }
}
