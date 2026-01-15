<?php
declare(strict_types=1);

use Laas\Http\Request;
use Laas\Modules\Api\Controller\AiController;
use PHPUnit\Framework\TestCase;

final class AiToolsControllerTest extends TestCase
{
    public function testToolsList(): void
    {
        $request = new Request('GET', '/api/v1/ai/tools', [], [], [], '');
        $request->setAttribute('api.user', ['id' => 1]);

        $controller = new AiController(null);
        $response = $controller->tools($request);

        $this->assertSame(200, $response->getStatus());
        $data = json_decode($response->getBody(), true);
        $this->assertIsArray($data);
        $tools = $data['data']['tools'] ?? [];
        $this->assertIsArray($tools);
    }

    public function testRunAllowedDryRun(): void
    {
        $request = new Request(
            'POST',
            '/api/v1/ai/run',
            [],
            [],
            ['content-type' => 'application/json'],
            json_encode([
                'plan' => [
                    'steps' => [
                        [
                            'command' => 'policy:check',
                            'args' => [],
                        ],
                    ],
                ],
            ], JSON_UNESCAPED_SLASHES)
        );
        $request->setAttribute('api.user', ['id' => 1]);

        $controller = new AiController(null);
        $response = $controller->runTools($request);

        $this->assertSame(200, $response->getStatus());
        $data = json_decode($response->getBody(), true);
        $this->assertIsArray($data);
        $this->assertArrayHasKey('outputs', $data['data'] ?? []);
    }

    public function testRunDisallowedCommand(): void
    {
        $request = new Request(
            'POST',
            '/api/v1/ai/run',
            [],
            [],
            ['content-type' => 'application/json'],
            json_encode([
                'plan' => [
                    'steps' => [
                        [
                            'command' => 'system:rm',
                            'args' => [],
                        ],
                    ],
                ],
            ], JSON_UNESCAPED_SLASHES)
        );
        $request->setAttribute('api.user', ['id' => 1]);

        $controller = new AiController(null);
        $response = $controller->runTools($request);

        $this->assertSame(403, $response->getStatus());
    }
}
