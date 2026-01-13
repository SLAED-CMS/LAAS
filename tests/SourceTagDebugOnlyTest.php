<?php
declare(strict_types=1);

use Laas\Http\ErrorCode;
use Laas\Http\ErrorResponse;
use Laas\Http\Request;
use Laas\Support\RequestScope;
use PHPUnit\Framework\TestCase;

final class SourceTagDebugOnlyTest extends TestCase
{
    public function testSourceTagOnlyWhenDebug(): void
    {
        $prev = $_ENV['APP_DEBUG'] ?? null;
        $request = new Request('GET', '/api/test', [], [], ['accept' => 'application/json'], '');

        try {
            $_ENV['APP_DEBUG'] = 'true';
            $respDebug = ErrorResponse::respond($request, ErrorCode::INTERNAL, [], 500, [], 'unit.test');
            $payloadDebug = json_decode($respDebug->getBody(), true);
            $this->assertSame('unit.test', $payloadDebug['error']['details']['source'] ?? null);

            $_ENV['APP_DEBUG'] = 'false';
            $respNoDebug = ErrorResponse::respond($request, ErrorCode::INTERNAL, [], 500, [], 'unit.test');
            $payloadNoDebug = json_decode($respNoDebug->getBody(), true);
            $this->assertArrayNotHasKey('details', $payloadNoDebug['error'] ?? []);
        } finally {
            if ($prev === null) {
                unset($_ENV['APP_DEBUG']);
            } else {
                $_ENV['APP_DEBUG'] = $prev;
            }
            RequestScope::reset();
            RequestScope::setRequest(null);
        }
    }
}
