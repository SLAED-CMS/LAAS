<?php
declare(strict_types=1);

use Laas\Http\Response;
use Laas\Http\UiEventRegistry;
use Laas\Http\UiToast;
use PHPUnit\Framework\TestCase;

final class JsonEventsPresenceTest extends TestCase
{
    protected function setUp(): void
    {
        UiEventRegistry::clear();
    }

    public function testJsonResponseIncludesEvents(): void
    {
        UiToast::registerSuccess('admin.pages.saved', 'Saved.');

        $response = Response::json([
            'data' => [],
        ]);

        $payload = json_decode($response->getBody(), true);
        $this->assertArrayHasKey('events', $payload['meta']);
        $this->assertSame('success', $payload['meta']['events'][0]['type'] ?? null);
        $this->assertSame('admin.pages.saved', $payload['meta']['events'][0]['message_key'] ?? null);
    }
}
