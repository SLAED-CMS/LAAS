<?php
declare(strict_types=1);

use Laas\Http\Request;
use Laas\Modules\Api\Controller\AiController;
use PHPUnit\Framework\TestCase;

final class AiControllerHtmxResponseTest extends TestCase
{
    public function testProposeReturnsHtmlForHtmx(): void
    {
        $request = new Request(
            'POST',
            '/api/v1/ai/propose',
            [],
            ['prompt' => 'Generate a demo proposal.'],
            ['hx-request' => 'true'],
            ''
        );
        $request->setAttribute('api.user', ['id' => 1]);

        $controller = new AiController(null);
        $response = $controller->propose($request);

        $this->assertSame(200, $response->getStatus());
        $body = $response->getBody();
        $this->assertStringContainsString('<pre', $body);
        $this->assertStringContainsString('proposal_json', $body);
        $this->assertStringContainsString('plan_json', $body);
    }
}
