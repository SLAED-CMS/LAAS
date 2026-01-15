<?php
declare(strict_types=1);

use Laas\Http\Request;
use Laas\Modules\Api\Controller\AiController;
use PHPUnit\Framework\TestCase;

final class AiProposeControllerTest extends TestCase
{
    public function testProposeRedactsPrompt(): void
    {
        $prompt = 'token=secret-123 email=test@example.com Authorization: Bearer ABCDEFG';
        $request = new Request(
            'POST',
            '/api/v1/ai/propose',
            [],
            [],
            ['content-type' => 'application/json'],
            json_encode(['prompt' => $prompt], JSON_UNESCAPED_SLASHES)
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
