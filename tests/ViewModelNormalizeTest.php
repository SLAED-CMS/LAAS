<?php
declare(strict_types=1);

use Laas\Auth\NullAuthService;
use Laas\Database\DatabaseManager;
use Laas\Http\Request;
use Laas\I18n\Translator;
use Laas\Modules\Pages\ViewModel\PagePublicViewModel;
use Laas\Settings\SettingsProvider;
use Laas\View\AssetManager;
use Laas\View\Template\TemplateCompiler;
use Laas\View\Template\TemplateEngine;
use Laas\View\Theme\ThemeManager;
use Laas\View\View;
use PHPUnit\Framework\TestCase;
use Tests\Support\InMemorySession;

final class ViewModelNormalizeTest extends TestCase
{
    public function testNestedViewModelIsNormalized(): void
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

        $session = new InMemorySession();
        $session->start();
        $request = new Request('GET', '/test', [], [], [], '', $session);
        $view->setRequest($request);

        $vm = new PagePublicViewModel('test', 'Hello', '<p>Body</p>');
        $response = $view->render('pages/page.html', [
            'page' => $vm,
            'legacy_content_allowed' => true,
        ]);

        $this->assertStringContainsString('Hello', $response->getBody());
        $this->assertStringContainsString('<p>Body</p>', $response->getBody());
    }
}
