<?php
declare(strict_types=1);

use Laas\Auth\NullAuthService;
use Laas\Database\DatabaseManager;
use Laas\Http\Request;
use Laas\I18n\Translator;
use Laas\Settings\SettingsProvider;
use Laas\View\AssetManager;
use Laas\View\Template\TemplateCompiler;
use Laas\View\Template\TemplateEngine;
use Laas\View\Theme\ThemeManager;
use Laas\View\View;
use PHPUnit\Framework\TestCase;
use Tests\Support\InMemorySession;

final class UiTokensPageRowSmokeTest extends TestCase
{
    public function testPageRowMapsUiStatusToBadge(): void
    {
        $root = dirname(__DIR__);
        $db = new DatabaseManager(['driver' => 'sqlite', 'database' => ':memory:']);
        $settings = new SettingsProvider($db, [
            'site_name' => 'LAAS',
            'default_locale' => 'en',
            'theme' => 'default',
        ], ['site_name', 'default_locale', 'theme']);

        $themeManager = new ThemeManager($root . '/themes', 'admin', $settings);
        $cachePath = $root . '/storage/cache/templates-tests';
        $engine = new TemplateEngine(
            $themeManager,
            new TemplateCompiler(),
            $cachePath,
            true
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
            $cachePath,
            $db
        );

        $session = new InMemorySession();
        $session->start();
        $request = new Request('GET', '/admin/pages', [], [], [], '', $session);
        $view->setRequest($request);

        $response = $view->render('partials/page_row.html', [
            'page' => [
                'id' => 1,
                'title_segments' => [['text' => 'Hello', 'mark' => false]],
                'slug_segments' => [['text' => 'hello', 'mark' => false]],
                'status' => 'published',
                'is_published' => true,
                'updated_at_display' => '-',
                'can_edit' => true,
                'ui' => [
                    'status' => 'active',
                    'severity' => 'low',
                    'visibility' => 'visible',
                ],
            ],
        ], 200, [], [
            'theme' => 'admin',
            'render_partial' => true,
        ]);

        $this->assertStringContainsString('text-bg-success', $response->getBody());
    }
}
