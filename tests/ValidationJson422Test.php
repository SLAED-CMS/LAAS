<?php
declare(strict_types=1);

use Laas\Http\Contract\ContractResponse;
use PHPUnit\Framework\TestCase;

final class ValidationJson422Test extends TestCase
{
    public function testValidationErrorsUse422(): void
    {
        $response = ContractResponse::error('validation_failed', ['route' => 'test.save'], 422, [
            'name' => ['required'],
        ]);

        $this->assertSame(422, $response->getStatus());
        $payload = json_decode($response->getBody(), true);
        $this->assertSame('E_VALIDATION_FAILED', $payload['error']['code'] ?? null);
        $this->assertSame(['required'], $payload['error']['details']['fields']['name'] ?? null);
    }
}
