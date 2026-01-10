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
use PHPUnit\Framework\TestCase;
use Tests\Support\InMemorySession;

final class UiTokensEnforcementTest extends TestCase
{
    public function testClassKeyProducesWarningInDevTools(): void
    {
        $root = dirname(__DIR__);
        $db = new DatabaseManager(['driver' => 'sqlite', 'database' => ':memory:']);
        $settings = new SettingsProvider($db, [
            'site_name' => 'LAAS',
            'default_locale' => 'en',
            'theme' => 'default',
        ], ['site_name', 'default_locale', 'theme']);

        $themeManager = new ThemeManager($root . '/themes', 'default', $settings);
        $engine = new TemplateEngine(
            $themeManager,
            new TemplateCompiler(),
            $root . '/storage/cache/templates-tests',
            true
        );
        $translator = new Translator($root, 'default', 'en');
        $view = new View(
            $themeManager,
            $engine,
            $translator,
            'en',
            ['name' => 'LAAS', 'debug' => true],
            new AssetManager([]),
            new NullAuthService(),
            $settings,
            $root . '/storage/cache/templates-tests',
            $db
        );

        $session = new InMemorySession();
        $session->start();
        $request = new Request('GET', '/', [], [], [], '', $session);
        $view->setRequest($request);

        $context = new DevToolsContext(['enabled' => true, 'request_id' => 'test']);
        RequestScope::set('devtools.context', $context);

        $response = $view->render('layout.html', [
            'foo_class' => 'text-bg-success',
        ]);

        $this->assertStringContainsString('<!doctype html>', $response->getBody());
        $warnings = $context->getWarnings();
        $this->assertNotEmpty($warnings);
    }
}
