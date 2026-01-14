<?php
declare(strict_types=1);

use Laas\Http\Response;
use Laas\Http\UiEventRegistry;
use Laas\Http\UiToast;
use PHPUnit\Framework\TestCase;

final class JsonMetaEventsToastSchemaTest extends TestCase
{
    protected function setUp(): void
    {
        UiEventRegistry::clear();
    }

    public function testJsonEventsAreLimitedAndContainSchema(): void
    {
        UiToast::registerSuccess('First', 'first');
        UiToast::registerSuccess('Second', 'second');
        UiToast::registerSuccess('Third', 'third');
        UiToast::registerSuccess('Fourth', 'fourth');

        $response = Response::json(['data' => []]);
        $payload = json_decode($response->getBody(), true);

        $this->assertIsArray($payload);
        $this->assertArrayHasKey('events', $payload['meta']);
        $this->assertLessThanOrEqual(3, count($payload['meta']['events']));

        foreach ($payload['meta']['events'] as $event) {
            $this->assertSame('success', $event['type'] ?? null);
            $this->assertNotSame('', (string) ($event['message'] ?? ''));
            $this->assertNotSame('', (string) ($event['request_id'] ?? ''));
        }
    }
}
