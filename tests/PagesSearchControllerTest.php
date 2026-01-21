<?php
declare(strict_types=1);

use Laas\Auth\NullAuthService;
use Laas\Database\DatabaseManager;
use Laas\Http\Request;
use Laas\I18n\Translator;
use Laas\Domain\Pages\PagesService;
use Laas\Modules\Pages\Controller\PagesController;
use Laas\Settings\SettingsProvider;
use Laas\View\Template\TemplateCompiler;
use Laas\View\Template\TemplateEngine;
use Laas\View\Theme\ThemeManager;
use Laas\View\AssetManager;
use Laas\View\View;
use PHPUnit\Framework\TestCase;

final class PagesSearchControllerTest extends TestCase
{
    private string $rootPath;

    protected function setUp(): void
    {
        $this->rootPath = dirname(__DIR__);
    }

    public function testTooShortQueryReturns422(): void
    {
        $db = new DatabaseManager(['driver' => 'sqlite', 'database' => ':memory:']);
        $request = new Request('GET', '/search', ['q' => 'a'], [], [], '');
        $view = $this->createView($db, $request);

        $controller = new PagesController($view, new PagesService($db));
        $response = $controller->search($request);

        $this->assertSame(422, $response->getStatus());
    }

    public function testSearchUsesPagesService(): void
    {
        $db = new DatabaseManager(['driver' => 'sqlite', 'database' => ':memory:']);
        $request = new Request('GET', '/search', ['q' => 'hello'], [], [], '');
        $view = $this->createView($db, $request);
        $service = new SpyPagesService($db);

        $controller = new PagesController($view, $service);
        $response = $controller->search($request);

        $this->assertSame(200, $response->getStatus());
        $this->assertTrue($service->listCalled);
        $this->assertSame('hello', $service->lastFilters['query'] ?? null);
        $this->assertSame('published', $service->lastFilters['status'] ?? null);
    }

    private function createView(DatabaseManager $db, Request $request): View
    {
        $settings = new SettingsProvider($db, [
            'site_name' => 'LAAS',
            'default_locale' => 'en',
            'theme' => 'default',
        ], ['site_name', 'default_locale', 'theme']);

        $themeManager = new ThemeManager($this->rootPath . '/themes', 'default', $settings);
        $engine = new TemplateEngine(
            $themeManager,
            new TemplateCompiler(),
            $this->rootPath . '/storage/cache/templates',
            false
        );
        $translator = new Translator($this->rootPath, 'default', 'en');
        $view = new View(
            $themeManager,
            $engine,
            $translator,
            'en',
            ['name' => 'LAAS', 'debug' => false],
            new AssetManager([]),
            new NullAuthService(),
            $settings,
            $this->rootPath . '/storage/cache/templates',
            $db
        );
        $view->setRequest($request);

        return $view;
    }
}

final class SpyPagesService extends PagesService
{
    public bool $listCalled = false;
    public array $lastFilters = [];

    public function list(array $filters = []): array
    {
        $this->listCalled = true;
        $this->lastFilters = $filters;

        return [[
            'title' => 'Hello',
            'slug' => 'hello',
            'content' => 'Hello world',
        ]];
    }
}
