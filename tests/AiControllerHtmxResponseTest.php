<?php
declare(strict_types=1);

use Laas\Ai\Provider\AiProviderInterface;
use Laas\Auth\NullAuthService;
use Laas\Database\DatabaseManager;
use Laas\Http\Request;
use Laas\I18n\Translator;
use Laas\Modules\Api\Controller\AiController;
use Laas\Settings\SettingsProvider;
use Laas\View\AssetManager;
use Laas\View\Template\TemplateCompiler;
use Laas\View\Template\TemplateEngine;
use Laas\View\Theme\ThemeManager;
use Laas\View\View;
use PHPUnit\Framework\TestCase;
use Tests\Security\Support\SecurityTestHelper;

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

        $controller = new AiController(null, $this->createView($request), new FakeAiProvider());
        $response = $controller->propose($request);

        $this->assertSame(200, $response->getStatus());
        $body = $response->getBody();
        $this->assertStringContainsString('data-ai-propose-result', $body);
        $this->assertStringContainsString('proposal_json', $body);
        $this->assertStringContainsString('plan_json', $body);
        $this->assertStringContainsString('Proposed changes', $body);
        $this->assertStringContainsString('docs/demo.txt', $body);
    }

    private function createView(Request $request): View
    {
        $pdo = SecurityTestHelper::createSqlitePdo();
        $db = SecurityTestHelper::dbManagerFromPdo($pdo);
        $settings = new SettingsProvider($db, [
            'site_name' => 'LAAS',
            'default_locale' => 'en',
            'theme' => 'admin',
        ], ['site_name', 'default_locale', 'theme']);

        $root = SecurityTestHelper::rootPath();
        $themeManager = new ThemeManager($root . '/themes', 'admin', $settings);
        $engine = new TemplateEngine(
            $themeManager,
            new TemplateCompiler(),
            $root . '/storage/cache/templates_ai',
            true
        );
        $translator = new Translator($root, 'admin', 'en');
        $view = new View(
            $themeManager,
            $engine,
            $translator,
            'en',
            ['name' => 'LAAS', 'debug' => true],
            new AssetManager([]),
            new NullAuthService(),
            $settings,
            $root . '/storage/cache/templates_ai',
            $db
        );
        $view->setRequest($request);

        return $view;
    }
}

final class FakeAiProvider implements AiProviderInterface
{
    public function propose(array $input): array
    {
        return [
            'proposal' => [
                'id' => 'demo-proposal',
                'created_at' => gmdate(DATE_ATOM),
                'kind' => 'demo',
                'summary' => 'Demo proposal',
                'file_changes' => [
                    [
                        'op' => 'create',
                        'path' => 'docs/demo.txt',
                        'content' => "a\nb",
                    ],
                ],
                'entity_changes' => [],
                'warnings' => [],
                'confidence' => 0.2,
                'risk' => 'low',
            ],
            'plan' => [
                'id' => 'demo-plan',
                'created_at' => gmdate(DATE_ATOM),
                'kind' => 'demo',
                'summary' => 'Demo plan',
                'steps' => [],
                'confidence' => 0.2,
                'risk' => 'low',
            ],
        ];
    }
}
