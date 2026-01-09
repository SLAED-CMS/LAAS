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

final class TemplateSnapshotTest extends TestCase
{
    public function testAdminModuleRowSnapshot(): void
    {
        $root = dirname(__DIR__);
        $db = new DatabaseManager(['driver' => 'sqlite', 'database' => ':memory:']);
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
            $root . '/storage/cache/templates',
            $db
        );

        $session = new InMemorySession();
        $session->start();
        $request = new Request('GET', '/admin/modules', [], [], [], '', $session);
        $view->setRequest($request);

        $html = $view->renderPartial('partials/module_row.html', [
            'module' => [
                'name' => 'TestModule',
                'version' => '1.0.0',
                'type' => 'admin',
                'type_label' => 'Admin',
                'type_is_internal' => false,
                'type_is_admin' => true,
                'type_is_api' => false,
                'enabled' => true,
                'source' => 'DB',
                'protected' => true,
            ],
        ], [
            'theme' => 'admin',
        ]);

        $expected = <<<HTML
<tr id="module-TestModule">
  <td>TestModule</td>
  <td>1.0.0</td>
  <td>
    <span class="badge bg-dark">Admin</span>
  </td>
  <td>
    <span class="badge text-bg-success">ON</span>
  </td>
  <td>
    <span class="badge text-bg-secondary">Protected</span>
  </td>
</tr>
HTML;

        $normalize = static function (string $value): string {
            $value = str_replace("\r\n", "\n", $value);
            $lines = array_map('trim', explode("\n", trim($value)));
            $lines = array_filter($lines, static fn(string $line): bool => $line !== '');
            return implode("\n", $lines);
        };

        $this->assertSame($normalize($expected), $normalize($html));
    }
}
