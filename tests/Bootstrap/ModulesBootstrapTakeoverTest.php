<?php

declare(strict_types=1);

use Laas\Bootstrap\BootContext;
use Laas\Bootstrap\BootstrapsRunner;
use Laas\Bootstrap\ObservabilityBootstrap;
use Laas\Bootstrap\SecurityBootstrap;
use Laas\Bootstrap\ModulesBootstrap;
use Laas\Bootstrap\RoutingBootstrap;
use Laas\Bootstrap\ViewBootstrap;
use Laas\Auth\NullAuthService;
use Laas\Core\Container\Container;
use Laas\Database\DatabaseManager;
use Laas\Events\EventDispatcherInterface;
use Laas\Events\SimpleEventDispatcher;
use Laas\Events\Http\ResponseEvent;
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
        $dispatcher = new SimpleEventDispatcher();
        $container->singleton(EventDispatcherInterface::class, static fn (): EventDispatcherInterface => $dispatcher);
        $container->singleton(View::class, fn (): View => $this->buildView());

        $module = new class implements ModuleLifecycleInterface {
            public function registerBindings(Container $container): void
            {
                $container->singleton('test.bound', static fn (): bool => true);
            }

            public function registerRoutes(Router $router): void
            {
                $router->addRoute('GET', '/_boot-test', [ModulesBootstrapTakeoverTest::class, 'handle']);
            }

            public function registerListeners(EventDispatcherInterface $events): void
            {
                $events->addListener(ResponseEvent::class, static function (ResponseEvent $event): void {
                    $event->response = $event->response->withHeader('X-Boot-Test', 'yes');
                });
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
            'bootstraps_enabled' => true,
            'bootstraps_modules_takeover' => true,
        ];
        $ctx = new BootContext(__DIR__, $container, $ctxConfig, true);

        $runner = new BootstrapsRunner([
            new SecurityBootstrap(),
            new ObservabilityBootstrap(),
            new ModulesBootstrap(),
            new RoutingBootstrap(),
            new ViewBootstrap(),
        ]);
        $runner->run($ctx);

        $request = new Request('GET', '/_boot-test', [], [], [], '');
        $response = $router->dispatch($request);
        $responseEvent = $dispatcher->dispatch(new ResponseEvent($request, $response));
        $response = $responseEvent instanceof ResponseEvent ? $responseEvent->response : $response;
        $this->assertSame(200, $response->getStatus());
        $this->assertSame('yes', $response->getHeader('X-Boot-Test'));
        $this->assertTrue($container->get('test.bound'));
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
