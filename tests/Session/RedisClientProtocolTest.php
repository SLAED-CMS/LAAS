<?php
declare(strict_types=1);

use Laas\Session\Redis\RedisClient;
use PHPUnit\Framework\TestCase;

final class RedisClientProtocolTest extends TestCase
{
    public function testBuildCommand(): void
    {
        $this->assertSame("*1\r\n\$4\r\nPING\r\n", RedisClient::buildCommand(['PING']));
        $this->assertSame(
            "*4\r\n\$5\r\nSETEX\r\n\$3\r\nkey\r\n\$2\r\n10\r\n\$5\r\nvalue\r\n",
            RedisClient::buildCommand(['SETEX', 'key', 10, 'value'])
        );
    }

    public function testParseSimpleAndIntegerResponses(): void
    {
        $this->assertSame('PONG', RedisClient::parseResponse("+PONG\r\n"));
        $this->assertSame(1, RedisClient::parseResponse(":1\r\n"));
    }

    public function testParseBulkAndArrayResponses(): void
    {
        $this->assertSame('foo', RedisClient::parseResponse("\$3\r\nfoo\r\n"));
        $this->assertNull(RedisClient::parseResponse("\$-1\r\n"));
        $this->assertSame(
            ['foo', 'bar'],
            RedisClient::parseResponse("*2\r\n\$3\r\nfoo\r\n\$3\r\nbar\r\n")
        );
    }

    public function testParseErrorResponseThrows(): void
    {
        $this->expectException(RuntimeException::class);
        RedisClient::parseResponse("-ERR boom\r\n");
    }
}
