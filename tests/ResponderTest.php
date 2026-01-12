<?php
declare(strict_types=1);

use Laas\Auth\NullAuthService;
use Laas\Database\DatabaseManager;
use Laas\Http\Request;
use Laas\Http\Responder;
use Laas\I18n\Translator;
use Laas\Settings\SettingsProvider;
use Laas\View\AssetManager;
use Laas\View\Template\TemplateCompiler;
use Laas\View\Template\TemplateEngine;
use Laas\View\Theme\ThemeManager;
use Laas\View\View;
use PHPUnit\Framework\TestCase;

final class ResponderTest extends TestCase
{
    private string $rootPath;

    protected function setUp(): void
    {
        $this->rootPath = dirname(__DIR__);
    }

    public function testRespondsWithJsonContentType(): void
    {
        $view = $this->createView();
        $request = new Request('GET', '/page', ['format' => 'json'], [], ['accept' => 'application/json'], '');
        $view->setRequest($request);

        $responder = new Responder($view);
        $data = $this->sampleData();

        $response = $responder->respond($request, 'pages/page.html', $data, $data);

        $this->assertSame('application/json; charset=utf-8', $response->getHeader('Content-Type'));
        $payload = json_decode($response->getBody(), true);
        $this->assertSame('json', $payload['meta']['format'] ?? null);
    }

    public function testRespondsWithHtmlContentType(): void
    {
        $view = $this->createView();
        $request = new Request('GET', '/page', [], [], ['accept' => 'text/html'], '');
        $view->setRequest($request);

        $responder = new Responder($view);
        $data = $this->sampleData();

        $response = $responder->respond($request, 'pages/page.html', $data, $data);

        $this->assertSame('text/html; charset=utf-8', $response->getHeader('Content-Type'));
    }

    public function testJsonPreferredOverHtmx(): void
    {
        $view = $this->createView();
        $request = new Request('GET', '/page', [], [], [
            'accept' => 'application/json',
            'hx-request' => 'true',
        ], '');
        $view->setRequest($request);

        $responder = new Responder($view);
        $data = $this->sampleData();

        $response = $responder->respond($request, 'pages/page.html', $data, $data);

        $this->assertSame('application/json; charset=utf-8', $response->getHeader('Content-Type'));
    }

    public function testWildcardAcceptDefaultsToHtml(): void
    {
        $view = $this->createView();
        $request = new Request('GET', '/page', [], [], ['accept' => '*/*'], '');
        $view->setRequest($request);

        $responder = new Responder($view);
        $data = $this->sampleData();

        $response = $responder->respond($request, 'pages/page.html', $data, $data);

        $this->assertSame('text/html; charset=utf-8', $response->getHeader('Content-Type'));
    }

    public function testHtmlAcceptsPreferHtml(): void
    {
        $view = $this->createView();
        $request = new Request('GET', '/page', [], [], ['accept' => 'text/html,application/xhtml+xml'], '');
        $view->setRequest($request);

        $responder = new Responder($view);
        $data = $this->sampleData();

        $response = $responder->respond($request, 'pages/page.html', $data, $data);

        $this->assertSame('text/html; charset=utf-8', $response->getHeader('Content-Type'));
    }

    private function sampleData(): array
    {
        return [
            'page' => [
                'title' => 'Hello',
                'content' => 'Body',
                'slug' => 'hello',
            ],
        ];
    }

    private function createView(): View
    {
        $db = new DatabaseManager(['driver' => 'sqlite', 'database' => ':memory:']);
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
            true
        );
        $translator = new Translator($this->rootPath, 'default', 'en');

        return new View(
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
    }
}
