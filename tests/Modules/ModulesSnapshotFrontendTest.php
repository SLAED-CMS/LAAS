<?php

declare(strict_types=1);

namespace {
    use Laas\Auth\NullAuthService;
    use Laas\Core\Container\Container;
    use Laas\Database\DatabaseManager;
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

    final class ModulesSnapshotFrontendTest extends TestCase
    {
        protected function tearDown(): void
        {
            RequestScope::setRequest(null);
            RequestScope::reset();
        }

        public function testFrontendUsesSnapshotWithoutDiscovery(): void
        {
            RequestScope::reset();
            $cachePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'laas-modules-snapshot-' . bin2hex(random_bytes(4)) . '.php';
            $payload = [
                'generated_at' => time(),
                'modules' => ['Demo'],
            ];
            file_put_contents($cachePath, "<?php\n\nreturn " . var_export($payload, true) . ";\n");

            $snapshot = new ModulesSnapshot($cachePath, 300, null);
            $container = new Container();
            $container->singleton(ModulesSnapshot::class, static fn (): ModulesSnapshot => $snapshot);
            RequestScope::setRequest(new Request('GET', '/', [], [], [], ''));

            \Laas\Modules\Demo\DemoModule::$registered = false;
            $router = new Router(sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'laas-modules-snapshot-router', true);
            $db = new DatabaseManager([
                'driver' => 'sqlite',
                'database' => ':memory:',
            ]);
            $view = $this->buildView($db);
            $manager = new ModuleManager([\Laas\Modules\Demo\DemoModule::class], $view, $db, $container);

            $manager->register($router);

            $stats = RequestScope::get('devtools.modules');
            $discoverCalls = is_array($stats) ? (int) ($stats['discover']['calls'] ?? 0) : 0;

            $this->assertSame(0, $discoverCalls);
            $this->assertTrue(\Laas\Modules\Demo\DemoModule::$registered);
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
}

namespace Laas\Modules\Demo {
    use Laas\Modules\ModuleInterface;
    use Laas\Routing\Router;

    final class DemoModule implements ModuleInterface
    {
        public static bool $registered = false;

        public function registerRoutes(Router $router): void
        {
            self::$registered = true;
        }
    }
}
