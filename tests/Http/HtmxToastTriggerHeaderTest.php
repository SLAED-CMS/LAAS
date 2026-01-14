<?php
declare(strict_types=1);

use Laas\Http\Response;
use PHPUnit\Framework\TestCase;

final class HtmxToastTriggerHeaderTest extends TestCase
{
    public function testWithToastSuccessAddsHtmxTrigger(): void
    {
        $response = (new Response())->withToastSuccess('admin.pages.saved', 'Saved.', 3000, ['module' => 'pages']);

        $this->assertSame(200, $response->getStatus());
        $header = $response->getHeader('HX-Trigger');
        $this->assertNotNull($header);

        $payload = json_decode($header, true);
        $this->assertIsArray($payload);
        $this->assertArrayHasKey('laas:toast', $payload);

        $toast = $payload['laas:toast'];
        $this->assertSame('success', $toast['type'] ?? null);
        $this->assertSame('admin.pages.saved', $toast['message_key'] ?? null);
        $this->assertSame('Saved.', $toast['message'] ?? null);
        $this->assertSame(3000, $toast['ttl_ms'] ?? null);
        $this->assertSame(['module' => 'pages'], $toast['context'] ?? null);
        $this->assertNotSame('', (string) ($toast['request_id'] ?? ''));
    }
}
