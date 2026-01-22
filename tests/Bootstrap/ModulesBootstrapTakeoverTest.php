<?php

declare(strict_types=1);

use Laas\Bootstrap\BootContext;
use Laas\Bootstrap\BootstrapsRunner;
use Laas\Bootstrap\ObservabilityBootstrap;
use Laas\Bootstrap\SecurityBootstrap;
use Laas\Bootstrap\ModulesBootstrap;
use Laas\Auth\NullAuthService;
use Laas\Core\Container\Container;
use Laas\Database\DatabaseManager;
use Laas\Events\EventDispatcherInterface;
use Laas\Events\SimpleEventDispatcher;
use Laas\Http\Request;
use Laas\Http\Response;
use Laas\Modules\ModuleLifecycleInterface;
use Laas\Modules\ModulesLoader;
use Laas\Routing\Router;
use Laas\Settings\SettingsProvider;
use Laas\View\AssetManager;
use Laas\View\Template\TemplateCompiler;
use Laas\View\Template\TemplateEngine;
use Laas\View\Theme\ThemeManager;
use Laas\View\View;
use Laas\I18n\Translator;
use PHPUnit\Framework\TestCase;

final class ModulesBootstrapTakeoverTest extends TestCase
{
    public static function handle(Request $request, array $vars = []): Response
    {
        return new Response('ok', 200);
    }

    public function testTakeoverRegistersRoutesAndListeners(): void
    {
        $container = new Container();
        $router = new Router(sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'laas-router-takeover-test', true);
        $container->singleton(Router::class, static fn (): Router => $router);
        $container->singleton(EventDispatcherInterface::class, static fn (): EventDispatcherInterface => new SimpleEventDispatcher());
        $container->singleton(View::class, fn (): View => $this->buildView());

        $module = new class implements ModuleLifecycleInterface {
            public bool $listenersCalled = false;

            public function registerBindings(Container $container): void
            {
            }

            public function registerRoutes(Router $router): void
            {
                $router->addRoute('GET', '/_boot-test', [ModulesBootstrapTakeoverTest::class, 'handle']);
            }

            public function registerListeners(EventDispatcherInterface $events): void
            {
                $this->listenersCalled = true;
            }
        };

        $loader = new class($module) {
            public function __construct(private object $module)
            {
            }

            /** @return list<object> */
            public function loadEnabledModules(): array
            {
                return [$this->module];
            }
        };

        $container->singleton(ModulesLoader::class, static fn () => $loader);

        $ctxConfig = [
            'app' => [
                'bootstraps_enabled' => true,
                'bootstraps_modules_takeover' => true,
            ],
        ];
        $ctx = new BootContext(__DIR__, $container, $ctxConfig, true);

        $runner = new BootstrapsRunner([new SecurityBootstrap(), new ObservabilityBootstrap(), new ModulesBootstrap()]);
        $runner->run($ctx);

        $response = $router->dispatch(new Request('GET', '/_boot-test', [], [], [], ''));
        $this->assertSame(200, $response->getStatus());
        $this->assertTrue($module->listenersCalled);
    }

    private function buildView(): View
    {
        $root = dirname(__DIR__, 2);
        $db = new DatabaseManager([
            'driver' => 'sqlite',
            'database' => ':memory:',
        ]);
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
