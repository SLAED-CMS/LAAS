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

    final class FrontendModulesDiscoveryBudgetTest extends TestCase
    {
        protected function tearDown(): void
        {
            RequestScope::setRequest(null);
            RequestScope::reset();
        }

        public function testFrontendAvoidsModulesDiscovery(): void
        {
            RequestScope::reset();
            $cachePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'laas-modules-snapshot-perf-' . bin2hex(random_bytes(4)) . '.php';
            $payload = [
                'generated_at' => time(),
                'modules' => ['DemoPerf'],
            ];
            file_put_contents($cachePath, "<?php\n\nreturn " . var_export($payload, true) . ";\n");

            $snapshot = new ModulesSnapshot($cachePath, 300, null);
            $container = new Container();
            $container->singleton(ModulesSnapshot::class, static fn (): ModulesSnapshot => $snapshot);
            RequestScope::setRequest(new Request('GET', '/', [], [], [], ''));

            $router = new Router(sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'laas-modules-snapshot-perf-router', true);
            $db = new DatabaseManager([
                'driver' => 'sqlite',
                'database' => ':memory:',
            ]);
            $view = $this->buildView($db);
            $manager = new ModuleManager([\Laas\Modules\DemoPerf\DemoPerfModule::class], $view, $db, $container);

            $manager->register($router);

            $stats = RequestScope::get('devtools.modules');
            $calls = is_array($stats) ? (int) ($stats['total']['calls'] ?? 0) : 0;
            $ms = is_array($stats) ? (float) ($stats['total']['ms'] ?? 0.0) : 0.0;

            $this->assertTrue($calls === 0 || $ms <= 0.5);
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

namespace Laas\Modules\DemoPerf {
    use Laas\Modules\ModuleInterface;
    use Laas\Routing\Router;

    final class DemoPerfModule implements ModuleInterface
    {
        public function registerRoutes(Router $router): void
        {
        }
    }
}
