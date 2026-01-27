<?php

declare(strict_types=1);

use Laas\Auth\NullAuthService;
use Laas\Core\Container\Container;
use Laas\Database\DatabaseManager;
use Laas\Database\Repositories\ModulesRepository;
use Laas\Http\Request;
use Laas\I18n\Translator;
use Laas\Modules\ModuleManager;
use Laas\Modules\ModulesSnapshot;
use Laas\Routing\Router;
use Laas\Settings\SettingsProvider;
use Laas\Support\RequestScope;
use Laas\View\AssetManager;
use Laas\View\Template\TemplateCompiler;
use Laas\View\Template\TemplateEngine;
use Laas\View\Theme\ThemeManager;
use Laas\View\View;
use PHPUnit\Framework\TestCase;

final class ModulesSnapshotInvalidationTest extends TestCase
{
    protected function tearDown(): void
    {
        RequestScope::setRequest(null);
        RequestScope::reset();
    }

    public function testInvalidateAndRebuildOnAdmin(): void
    {
        $cachePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'laas-modules-snapshot-invalidate-' . bin2hex(random_bytes(4)) . '.php';
        $payload = [
            'generated_at' => time(),
            'modules' => ['Demo'],
        ];
        file_put_contents($cachePath, "<?php\n\nreturn " . var_export($payload, true) . ";\n");

        $db = new DatabaseManager([
            'driver' => 'sqlite',
            'database' => ':memory:',
        ]);
        $pdo = $db->pdo();
        $pdo->exec(
            'CREATE TABLE modules (
                name TEXT PRIMARY KEY,
                enabled INTEGER,
                version TEXT,
                installed_at TEXT,
                updated_at TEXT
            )'
        );

        $snapshot = new ModulesSnapshot($cachePath, 300, $db);
        RequestScope::set('modules.snapshot', $snapshot);

        $repo = new ModulesRepository($pdo);
        $repo->enable('Demo');

        $this->assertFileDoesNotExist($cachePath);

        $container = new Container();
        $container->singleton(ModulesSnapshot::class, static fn (): ModulesSnapshot => $snapshot);
        RequestScope::setRequest(new Request('GET', '/admin', [], [], [], ''));

        $router = new Router(sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'laas-modules-snapshot-admin', true);
        $view = $this->buildView($db);
        $manager = new ModuleManager([], $view, $db, $container);
        $manager->register($router);

        $this->assertFileExists($cachePath);
    }

    private function buildView(DatabaseManager $db): View
    {
        $root = dirname(__DIR__, 2);
        $settingsProvider = new SettingsProvider($db, [
            'site_name' => 'LAAS',
            'default_locale' => 'en',
            'theme' => 'default',
        ], ['site_name', 'default_locale', 'theme']);
        $themeManager = new ThemeManager($root . '/themes', 'default', $settingsProvider);
        $templateEngine = new TemplateEngine(
            $themeManager,
            new TemplateCompiler(),
            sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'laas-test-templates',
            false
        );
        $translator = new Translator($root, 'default', 'en');
        $assetManager = new AssetManager([]);
        $auth = new NullAuthService();

        return new View(
            $themeManager,
            $templateEngine,
            $translator,
            'en',
            ['debug' => false],
            $assetManager,
            $auth,
            $settingsProvider,
            sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'laas-test-templates'
        );
    }
}
