<?php

declare(strict_types=1);

use Laas\Auth\NullAuthService;
use Laas\Core\Container\Container;
use Laas\Database\DatabaseManager;
use Laas\Events\EventDispatcherInterface;
use Laas\Events\SimpleEventDispatcher;
use Laas\I18n\Translator;
use Laas\Modules\ModuleLifecycleInterface;
use Laas\Routing\Router;
use Laas\Settings\SettingsProvider;
use Laas\View\AssetManager;
use Laas\View\Template\TemplateCompiler;
use Laas\View\Template\TemplateEngine;
use Laas\View\Theme\ThemeManager;
use Laas\View\View;
use PHPUnit\Framework\TestCase;

final class LifecycleModulesSmokeTest extends TestCase
{
    public function testLifecycleModulesRegisterWithoutExceptions(): void
    {
        $root = dirname(__DIR__, 2);
        $moduleClasses = require $root . '/config/modules.php';

        $view = $this->buildView($root);
        $container = new Container();
        $container->singleton(View::class, static fn (): View => $view);

        $router = new Router($this->tempRouterPath(), true);
        $events = new SimpleEventDispatcher();
        $container->singleton(EventDispatcherInterface::class, static fn (): EventDispatcherInterface => $events);

        foreach ($moduleClasses as $class) {
            if (!is_string($class) || !class_exists($class)) {
                continue;
            }

            $module = $this->instantiateModule($class, $view, $container);
            if (!$module instanceof ModuleLifecycleInterface) {
                continue;
            }

            $module->registerBindings($container);
            $module->registerRoutes($router);
            $module->registerListeners($events);
        }

        $this->assertTrue(true);
    }

    private function instantiateModule(string $class, View $view, Container $container): object
    {
        $ctor = (new \ReflectionClass($class))->getConstructor();
        if ($ctor === null) {
            return new $class();
        }

        $paramCount = $ctor->getNumberOfParameters();
        if ($paramCount >= 3) {
            return new $class($view, null, $container);
        }
        if ($paramCount >= 2) {
            return new $class($view, null);
        }
        if ($paramCount >= 1) {
            return new $class($view);
        }

        return new $class();
    }

    private function tempRouterPath(): string
    {
        return sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'laas-lifecycle-smoke';
    }

    private function buildView(string $root): View
    {
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
