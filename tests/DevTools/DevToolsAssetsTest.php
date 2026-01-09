<?php
declare(strict_types=1);

use Laas\Auth\NullAuthService;
use Laas\Database\DatabaseManager;
use Laas\DevTools\DevToolsContext;
use Laas\Http\Request;
use Laas\I18n\Translator;
use Laas\Settings\SettingsProvider;
use Laas\Support\RequestScope;
use Laas\View\AssetManager;
use Laas\View\Template\TemplateCompiler;
use Laas\View\Template\TemplateEngine;
use Laas\View\Theme\ThemeManager;
use Laas\View\View;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\InMemorySession;

final class DevToolsAssetsTest extends TestCase
{
    #[DataProvider('themeProvider')]
    public function testDevToolsAssetsOnlyWhenEnabled(string $theme): void
    {
        $root = dirname(__DIR__, 2);
        $db = new DatabaseManager(['driver' => 'sqlite', 'database' => ':memory:']);
        $settings = new SettingsProvider($db, [
            'site_name' => 'LAAS',
            'default_locale' => 'en',
            'theme' => $theme,
        ], ['site_name', 'default_locale', 'theme']);

        $themeManager = new ThemeManager($root . '/themes', $theme, $settings);
        $engine = new TemplateEngine(
            $themeManager,
            new TemplateCompiler(),
            $root . '/storage/cache/templates',
            true
        );
        $translator = new Translator($root, $theme, 'en');
        $assetConfig = require $root . '/config/assets.php';
        $view = new View(
            $themeManager,
            $engine,
            $translator,
            'en',
            ['name' => 'LAAS', 'debug' => false],
            new AssetManager($assetConfig),
            new NullAuthService(),
            $settings,
            $root . '/storage/cache/templates',
            $db
        );

        $session = new InMemorySession();
        $session->start();
        $request = new Request('GET', '/', [], [], [], '', $session);
        $view->setRequest($request);

        RequestScope::setRequest(null);
        RequestScope::reset();
        RequestScope::set('devtools.context', new DevToolsContext(['enabled' => false]));
        $html = $view->renderPartial('layout.html', [], ['theme' => $theme]);
        $this->assertStringNotContainsString('devtools.css', $html);
        $this->assertStringNotContainsString('devtools.js', $html);

        RequestScope::reset();
        RequestScope::set('devtools.context', new DevToolsContext(['enabled' => true]));
        $html = $view->renderPartial('layout.html', [], ['theme' => $theme]);
        $this->assertStringContainsString('devtools.css', $html);
        $this->assertStringContainsString('devtools.js', $html);

        RequestScope::reset();
    }

    public static function themeProvider(): array
    {
        return [
            ['default'],
            ['admin'],
        ];
    }
}
