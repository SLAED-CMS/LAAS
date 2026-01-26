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
use Tests\Security\Support\SecurityTestHelper;
use Tests\Support\InMemorySession;

final class TemplateFlagGatingTest extends TestCase
{
    public function testTemplatesShowMarkersWhenEnabled(): void
    {
        $view = $this->createView();
        $view->share('admin_features', $this->enabledFlags());

        $header = $view->render('partials/header.html', [], 200, [], [
            'theme' => 'admin',
            'render_partial' => true,
        ])->getBody();
        $this->assertStringContainsString('data-palette-open="1"', $header);

        $pageForm = $this->renderPageForm($view)->getBody();
        $this->assertStringContainsString('Blocks (JSON)', $pageForm);

        $themes = $this->renderThemes($view)->getBody();
        $this->assertStringContainsString('data-theme-validate="1"', $themes);

        $headless = $this->renderHeadless($view)->getBody();
        $this->assertStringContainsString('data-headless-form="1"', $headless);
        $this->assertStringContainsString('data-headless-result="1"', $headless);
    }

    public function testTemplatesHideMarkersWhenDisabled(): void
    {
        $view = $this->createView();
        $view->share('admin_features', $this->disabledFlags());

        $header = $view->render('partials/header.html', [], 200, [], [
            'theme' => 'admin',
            'render_partial' => true,
        ])->getBody();
        $this->assertStringNotContainsString('data-palette-open="1"', $header);

        $pageForm = $this->renderPageForm($view)->getBody();
        $this->assertStringNotContainsString('Blocks (JSON)', $pageForm);

        $themes = $this->renderThemes($view)->getBody();
        $this->assertStringNotContainsString('data-theme-validate="1"', $themes);

        $headless = $this->renderHeadless($view)->getBody();
        $this->assertStringNotContainsString('data-headless-form="1"', $headless);
        $this->assertStringNotContainsString('data-headless-result="1"', $headless);
    }

    private function renderPageForm(View $view): \Laas\Http\Response
    {
        return $view->render('pages/page_form.html', [
            'mode' => 'edit',
            'is_edit' => true,
            'page' => [
                'id' => 1,
                'title' => 'Sample',
                'slug' => 'sample',
                'content' => '',
                'blocks_json' => '[]',
            ],
            'status_selected_draft' => 'selected',
            'status_selected_published' => '',
            'legacy_content' => false,
            'blocks_json_allowed' => true,
            'blocks_registry_types' => ['rich_text'],
            'editor_selection_source' => 'default',
            'editor_selected_id' => 'textarea',
            'editor_selected_format' => 'html',
            'editors' => [
                [
                    'id' => 'tinymce',
                    'label' => 'HTML (TinyMCE)',
                    'format' => 'html',
                    'available' => false,
                    'reason' => 'vendor_assets_missing',
                    'selected' => false,
                ],
                [
                    'id' => 'toastui',
                    'label' => 'Markdown (Toast UI)',
                    'format' => 'markdown',
                    'available' => false,
                    'reason' => 'vendor_assets_missing',
                    'selected' => false,
                ],
                [
                    'id' => 'textarea',
                    'label' => 'Plain textarea',
                    'format' => 'html',
                    'available' => true,
                    'reason' => '',
                    'selected' => true,
                ],
            ],
            'editor_caps' => [
                'tinymce' => ['available' => false, 'reason' => 'vendor_assets_missing'],
                'toastui' => ['available' => false, 'reason' => 'vendor_assets_missing'],
                'textarea' => ['available' => true, 'reason' => ''],
            ],
            'editor_assets' => [
                'tinymce' => ['js' => '', 'css' => ''],
                'toastui' => ['js' => '', 'css' => ''],
                'textarea' => ['js' => '', 'css' => ''],
                'pages_admin_editors_js' => '',
            ],
        ], 200, [], [
            'theme' => 'admin',
            'render_partial' => true,
        ]);
    }

    private function renderThemes(View $view): \Laas\Http\Response
    {
        return $view->render('pages/themes.html', [
            'theme_name' => 'admin',
            'theme_api' => 'v2',
            'theme_version' => '1.0.0',
            'theme_capabilities' => ['headless'],
            'theme_provides' => [],
            'theme_snapshot_hash' => '',
            'theme_current_hash' => '',
            'theme_manifest_path' => 'themes/admin/theme.json',
            'theme_validation' => ['violations' => [], 'warnings' => []],
            'theme_validation_ok' => true,
            'theme_debug' => true,
        ], 200, [], [
            'theme' => 'admin',
            'render_partial' => true,
        ]);
    }

    private function renderHeadless(View $view): \Laas\Http\Response
    {
        return $view->render('pages/headless_playground.html', [
            'default_url' => '/api/v2/pages?limit=1',
            'input_url' => '/api/v2/pages?limit=1',
            'fetch_result' => null,
        ], 200, [], [
            'theme' => 'admin',
            'render_partial' => true,
        ]);
    }

    private function createView(): View
    {
        $pdo = SecurityTestHelper::createSqlitePdo();
        SecurityTestHelper::seedSettingsTable($pdo);
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
            $root . '/storage/cache/templates_flags',
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
            $root . '/storage/cache/templates_flags',
            $db
        );

        $session = new InMemorySession();
        $session->start();
        $request = new Request('GET', '/admin', [], [], [], '', $session);
        $view->setRequest($request);

        return $view;
    }

    /**
     * @return array<string, bool>
     */
    private function enabledFlags(): array
    {
        return [
            'palette' => true,
            'blocks_studio' => true,
            'theme_inspector' => true,
            'headless_playground' => true,
        ];
    }

    /**
     * @return array<string, bool>
     */
    private function disabledFlags(): array
    {
        return [
            'palette' => false,
            'blocks_studio' => false,
            'theme_inspector' => false,
            'headless_playground' => false,
        ];
    }
}
