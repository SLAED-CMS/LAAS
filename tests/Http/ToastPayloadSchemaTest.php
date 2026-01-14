<?php
declare(strict_types=1);

use Laas\Http\UiToast;
use PHPUnit\Framework\TestCase;

final class ToastPayloadSchemaTest extends TestCase
{
    public function testToastPayloadHasRequiredFieldsAndNoHtml(): void
    {
        $payload = UiToast::payload(
            'success',
            'Hello <b>world</b>',
            '<i>Title</i>',
            'forms.validation_failed',
            4000,
            'dedupe-key'
        );

        $this->assertSame('success', $payload['type'] ?? null);
        $this->assertSame('Hello world', $payload['message'] ?? null);
        $this->assertSame('Title', $payload['title'] ?? null);
        $this->assertSame('forms.validation_failed', $payload['code'] ?? null);
        $this->assertSame('dedupe-key', $payload['dedupe_key'] ?? null);
        $this->assertNotSame('', (string) ($payload['request_id'] ?? ''));
        $this->assertSame(4000, $payload['ttl_ms'] ?? null);
        $this->assertStringNotContainsString('<', (string) ($payload['message'] ?? ''));
        $this->assertStringNotContainsString('>', (string) ($payload['message'] ?? ''));
        $this->assertStringNotContainsString('<', (string) ($payload['title'] ?? ''));
    }
}
