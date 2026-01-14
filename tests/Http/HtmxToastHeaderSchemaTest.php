<?php
declare(strict_types=1);

use Laas\Http\Response;
use PHPUnit\Framework\TestCase;

final class HtmxToastHeaderSchemaTest extends TestCase
{
    public function testHtmxHeaderContainsToastPayload(): void
    {
        $response = (new Response())->withToastSuccess('admin.pages.saved', 'Saved.');

        $header = $response->getHeader('HX-Trigger');
        $this->assertNotNull($header);

        $payload = json_decode($header, true);
        $this->assertIsArray($payload);
        $this->assertArrayHasKey('laas:toast', $payload);

        $toast = $payload['laas:toast'];
        $this->assertSame('success', $toast['type'] ?? null);
        $this->assertSame('Saved.', $toast['message'] ?? null);
        $this->assertNotSame('', (string) ($toast['request_id'] ?? ''));
    }
}
