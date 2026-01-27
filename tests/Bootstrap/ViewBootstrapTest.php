<?php

declare(strict_types=1);

use Laas\Bootstrap\BootContext;
use Laas\Bootstrap\BootstrapsRunner;
use Laas\Bootstrap\ViewBootstrap;
use Laas\Core\Container\Container;
use Laas\Theme\ThemeRegistry;
use PHPUnit\Framework\TestCase;

final class ViewBootstrapTest extends TestCase
{
    public function testViewBootstrapEnsuresCacheAndThemeDirs(): void
    {
        $root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'laas-view-bootstrap-' . bin2hex(random_bytes(4));
        $viewsDir = $root . DIRECTORY_SEPARATOR . 'themes' . DIRECTORY_SEPARATOR . 'default' . DIRECTORY_SEPARATOR . 'views';
        mkdir($viewsDir, 0777, true);

        $container = new Container();
        $container->singleton(ThemeRegistry::class, static function () use ($root): ThemeRegistry {
            return new ThemeRegistry($root . DIRECTORY_SEPARATOR . 'themes', 'default');
        });

        $ctx = new BootContext($root, $container, [
            'view_sanity_strict' => true,
        ], true);

        $runner = new BootstrapsRunner([new ViewBootstrap()]);
        $runner->run($ctx);

        $this->assertTrue($container->get('boot.view'));
        $this->assertDirectoryExists($root . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'cache' . DIRECTORY_SEPARATOR . 'templates');
        $this->assertDirectoryExists($viewsDir);
    }

    public function testSkipsWhenThemeRegistryMissing(): void
    {
        $root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'laas-view-bootstrap-missing-' . bin2hex(random_bytes(4));
        $container = new Container();

        $ctx = new BootContext($root, $container, [
            'view_sanity_strict' => true,
        ], true);

        $runner = new BootstrapsRunner([new ViewBootstrap()]);
        $runner->run($ctx);

        $this->assertTrue($container->get('boot.view'));
    }
}
