<?php
declare(strict_types=1);

use Laas\Http\Request;
use Laas\Modules\Api\Controller\AiController;
use PHPUnit\Framework\TestCase;

final class AiProposeContextRedactionTest extends TestCase
{
    public function testContextValueIsRedacted(): void
    {
        $payload = [
            'prompt' => 'Improve the focused field.',
            'context' => [
                'field' => 'content',
                'value' => 'token=secret-123 email=test@example.com Authorization: Bearer ABCDEFG',
                'page_id' => 12,
                'url' => '/demo',
            ],
        ];

        $request = new Request(
            'POST',
            '/api/v1/ai/propose',
            [],
            [],
            ['content-type' => 'application/json'],
            json_encode($payload, JSON_UNESCAPED_SLASHES)
        );
        $request->setAttribute('api.user', ['id' => 1]);

        $controller = new AiController(null);
        $response = $controller->propose($request);

        $this->assertSame(200, $response->getStatus());
        $body = $response->getBody();
        $this->assertStringContainsString('"proposal"', $body);
        $this->assertStringContainsString('"plan"', $body);
        $this->assertStringNotContainsString('secret-123', $body);
        $this->assertStringNotContainsString('test@example.com', $body);
        $this->assertStringNotContainsString('ABCDEFG', $body);
    }
}
