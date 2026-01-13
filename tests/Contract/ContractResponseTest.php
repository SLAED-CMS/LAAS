<?php
declare(strict_types=1);

use Laas\Http\Contract\ContractResponse;
use PHPUnit\Framework\TestCase;

final class ContractResponseTest extends TestCase
{
    public function testOkEnvelope(): void
    {
        $response = ContractResponse::ok(['foo' => 'bar'], ['route' => 'test.ok'], 201);

        $this->assertSame(201, $response->getStatus());
        $payload = json_decode($response->getBody(), true);
        $this->assertSame('bar', $payload['data']['foo'] ?? null);
        $this->assertSame('json', $payload['meta']['format'] ?? null);
        $this->assertSame('test.ok', $payload['meta']['route'] ?? null);
        $this->assertArrayHasKey('request_id', $payload['meta'] ?? []);
        $this->assertArrayHasKey('ts', $payload['meta'] ?? []);
    }

    public function testErrorEnvelopeWithFields(): void
    {
        $response = ContractResponse::error('validation_failed', ['route' => 'test.save'], 422, [
            'name' => ['required'],
        ]);

        $this->assertSame(422, $response->getStatus());
        $payload = json_decode($response->getBody(), true);
        $this->assertSame('E_VALIDATION_FAILED', $payload['error']['code'] ?? null);
        $this->assertSame('json', $payload['meta']['format'] ?? null);
        $this->assertSame('test.save', $payload['meta']['route'] ?? null);
        $this->assertSame(['required'], $payload['error']['details']['fields']['name'] ?? null);
        $this->assertArrayHasKey('request_id', $payload['meta'] ?? []);
        $this->assertArrayHasKey('ts', $payload['meta'] ?? []);
    }
}
