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

    public function testRemoteDisabledReturnsError(): void
    {
        $root = dirname(__DIR__);
        $localPath = $root . '/config/security.local.php';
        $payload = "<?php\n"
            . "declare(strict_types=1);\n\n"
            . "return [\n"
            . "    'ai_provider' => 'remote_http',\n"
            . "    'ai_remote_enabled' => false,\n"
            . "    'ai_remote_allowlist' => ['https://ai.example.com'],\n"
            . "];\n";

        file_put_contents($localPath, $payload);

        try {
            $request = new Request(
                'POST',
                '/api/v1/ai/propose',
                [],
                [],
                ['content-type' => 'application/json'],
                json_encode(['prompt' => 'test'], JSON_UNESCAPED_SLASHES)
            );
            $request->setAttribute('api.user', ['id' => 1]);

            $controller = new AiController(null);
            $response = $controller->propose($request);

            $this->assertSame(503, $response->getStatus());
            $data = json_decode($response->getBody(), true);
            $this->assertIsArray($data);
            $this->assertSame('remote_ai_disabled', $data['error']['details']['reason'] ?? null);
        } finally {
            @unlink($localPath);
        }
    }
}
