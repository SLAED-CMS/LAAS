<?php
declare(strict_types=1);

use Laas\Auth\NullAuthService;
use Laas\Database\DatabaseManager;
use Laas\Http\Middleware\ReadOnlyMiddleware;
use Laas\Http\Request;
use Laas\Http\Response;
use Laas\I18n\Translator;
use Laas\Settings\SettingsProvider;
use Laas\View\Template\TemplateCompiler;
use Laas\View\Template\TemplateEngine;
use Laas\View\Theme\ThemeManager;
use Laas\View\AssetManager;
use Laas\View\View;
use PHPUnit\Framework\TestCase;

final class ReadOnlyMiddlewareTest extends TestCase
{
    public function testReadOnlyBlocksPost(): void
    {
        $translator = new Translator(dirname(__DIR__), 'default', 'en');
        $middleware = new ReadOnlyMiddleware(true, $translator);

        $request = new Request('POST', '/admin/media/upload', [], [], [], '');
        $response = $middleware->process($request, static fn (Request $req): Response => new Response('OK', 200));

        $this->assertSame(503, $response->getStatus());
    }

    public function testReadOnlyAllowsGet(): void
    {
        $translator = new Translator(dirname(__DIR__), 'default', 'en');
        $middleware = new ReadOnlyMiddleware(true, $translator);

        $request = new Request('GET', '/admin/media', [], [], [], '');
        $response = $middleware->process($request, static fn (Request $req): Response => new Response('OK', 200));

        $this->assertSame(200, $response->getStatus());
    }

    public function testReadOnlyAllowsLogin(): void
    {
        $translator = new Translator(dirname(__DIR__), 'default', 'en');
        $middleware = new ReadOnlyMiddleware(true, $translator);

        $request = new Request('POST', '/login', [], [], [], '');
        $response = $middleware->process($request, static fn (Request $req): Response => new Response('OK', 200));

        $this->assertSame(200, $response->getStatus());
    }

    public function testReadOnlyAllowsCsrf(): void
    {
        $translator = new Translator(dirname(__DIR__), 'default', 'en');
        $middleware = new ReadOnlyMiddleware(true, $translator);

        $request = new Request('POST', '/csrf', [], [], [], '');
        $response = $middleware->process($request, static fn (Request $req): Response => new Response('OK', 200));

        $this->assertSame(200, $response->getStatus());
    }

    public function testReadOnlyHtmxReturnsErrorTemplate(): void
    {
        $root = dirname(__DIR__);
        $db = $this->createDatabase();
        $request = new Request('POST', '/admin/media/upload', [], [], [
            'hx-request' => 'true',
        ], '');

        $view = $this->createView($root, $db, $request);
        $translator = new Translator($root, 'admin', 'en');
        $middleware = new ReadOnlyMiddleware(true, $translator, $view);

        $response = $middleware->process($request, static fn (Request $req): Response => new Response('OK', 200));

        $this->assertSame(503, $response->getStatus());
        $this->assertNotNull($response->getHeader('HX-Trigger'));
        $this->assertStringContainsString('Read-only mode', $response->getBody());
    }

    private function createDatabase(): DatabaseManager
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        $db = new DatabaseManager(['driver' => 'mysql']);
        $ref = new ReflectionProperty($db, 'pdo');
        $ref->setAccessible(true);
        $ref->setValue($db, $pdo);

        return $db;
    }

    private function createView(string $root, DatabaseManager $db, Request $request): View
    {
        $settings = new SettingsProvider($db, [
            'site_name' => 'LAAS',
            'default_locale' => 'en',
            'theme' => 'admin',
        ], ['site_name', 'default_locale', 'theme']);

        $themeManager = new ThemeManager($root . '/themes', 'admin', $settings);
        $engine = new TemplateEngine(
            $themeManager,
            new TemplateCompiler(),
            $root . '/storage/cache/templates',
            false
        );
        $translator = new Translator($root, 'admin', 'en');
        $view = new View(
            $themeManager,
            $engine,
            $translator,
            'en',
            ['name' => 'LAAS', 'debug' => false],
            new AssetManager([]),
            new NullAuthService(),
            $settings,
            $root . '/storage/cache/templates',
            $db
        );
        $view->setRequest($request);

        return $view;
    }
}
