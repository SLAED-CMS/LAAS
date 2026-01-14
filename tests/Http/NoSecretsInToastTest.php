<?php
declare(strict_types=1);

use Laas\Http\Response;
use PHPUnit\Framework\TestCase;

final class NoSecretsInToastTest extends TestCase
{
    public function testToastPayloadUsesAllowedKeysOnly(): void
    {
        $response = (new Response())->withToastSuccess('admin.pages.saved', 'Saved.', 2500, ['area' => 'pages']);
        $header = $response->getHeader('HX-Trigger');
        $this->assertNotNull($header);

        $payload = json_decode($header, true);
        $toast = $payload['laas:toast'] ?? [];
        $allowed = ['type', 'message_key', 'message', 'request_id', 'context', 'ttl_ms'];

        foreach (array_keys($toast) as $key) {
            $this->assertContains($key, $allowed);
        }

        $message = (string) ($toast['message'] ?? '');
        $forbidden = [
            '/token/i',
            '/\\b(select|insert|update|delete|drop|union)\\b/i',
            '/stack\\s*trace|exception/i',
        ];
        foreach ($forbidden as $pattern) {
            $this->assertDoesNotMatchRegularExpression($pattern, $message);
        }
    }
}
