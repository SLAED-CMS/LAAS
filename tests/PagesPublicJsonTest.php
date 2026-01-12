<?php
declare(strict_types=1);

use Laas\Auth\NullAuthService;
use Laas\Database\DatabaseManager;
use Laas\Http\Request;
use Laas\I18n\Translator;
use Laas\Modules\Pages\Controller\PagesController;
use Laas\Settings\SettingsProvider;
use Laas\View\AssetManager;
use Laas\View\Template\TemplateCompiler;
use Laas\View\Template\TemplateEngine;
use Laas\View\Theme\ThemeManager;
use Laas\View\View;
use PHPUnit\Framework\TestCase;

final class PagesPublicJsonTest extends TestCase
{
    public function testPublicPageJsonResponse(): void
    {
        $root = dirname(__DIR__);
        $db = new DatabaseManager(['driver' => 'sqlite', 'database' => ':memory:']);
        $pdo = $db->pdo();
        $pdo->exec('CREATE TABLE pages (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            title TEXT,
            slug TEXT,
            content TEXT,
            status TEXT,
            created_at TEXT,
            updated_at TEXT
        )');
        $pdo->exec("INSERT INTO pages (title, slug, content, status, created_at, updated_at) VALUES (
            'Hello',
            'hello',
            'Body',
            'published',
            '2026-01-01 00:00:00',
            '2026-01-01 00:00:00'
        )");

        $settings = new SettingsProvider($db, [
            'site_name' => 'LAAS',
            'default_locale' => 'en',
            'theme' => 'default',
        ], ['site_name', 'default_locale', 'theme']);

        $themeManager = new ThemeManager($root . '/themes', 'default', $settings);
        $engine = new TemplateEngine(
            $themeManager,
            new TemplateCompiler(),
            $root . '/storage/cache/templates',
            true
        );
        $translator = new Translator($root, 'default', 'en');
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

        $controller = new PagesController($view, $db);
        $request = new Request('GET', '/hello', [], [], ['accept' => 'application/json'], '');
        $view->setRequest($request);

        $response = $controller->show($request, ['slug' => 'hello']);
        $this->assertSame(200, $response->getStatus());

        $data = json_decode($response->getBody(), true);
        $this->assertArrayHasKey('data', $data);
        $this->assertArrayHasKey('meta', $data);
        $this->assertSame('json', $data['meta']['format'] ?? null);
        $this->assertSame('pages.show', $data['meta']['route'] ?? null);
        $payload = is_array($data['data'] ?? null) ? $data['data'] : [];
        $this->assertSame('Hello', $payload['page']['title'] ?? null);
        $this->assertSame('Body', $payload['page']['content'] ?? null);
        $this->assertSame('hello', $payload['page']['slug'] ?? null);
    }
}
