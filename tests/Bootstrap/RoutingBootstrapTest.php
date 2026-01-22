<?php

declare(strict_types=1);

use Laas\Bootstrap\BootContext;
use Laas\Bootstrap\BootstrapsRunner;
use Laas\Bootstrap\RoutingBootstrap;
use Laas\Core\Container\Container;
use Laas\Http\Request;
use Laas\Http\Response;
use Laas\Routing\Router;
use PHPUnit\Framework\TestCase;

final class RoutingBootstrapTest extends TestCase
{
    public static function handle(Request $request, array $vars = []): Response
    {
        return new Response('ok', 200);
    }

    public function testRoutingBootstrapWarmsCache(): void
    {
        $cacheDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'laas-routing-bootstrap-' . bin2hex(random_bytes(4));
        $router = new Router($cacheDir, true);
        $router->addRoute('GET', '/_routing-test', [self::class, 'handle']);

        $container = new Container();
        $container->singleton(Router::class, static fn (): Router => $router);

        $ctxConfig = [
            'app' => [
                'bootstraps_enabled' => true,
                'routing_cache_warm' => true,
            ],
        ];
        $ctx = new BootContext(__DIR__, $container, $ctxConfig, true);

        $runner = new BootstrapsRunner([new RoutingBootstrap()]);
        $runner->run($ctx);

        $this->assertFileExists($cacheDir . DIRECTORY_SEPARATOR . 'routes.php');
        $this->assertFileExists($cacheDir . DIRECTORY_SEPARATOR . 'routes.sha1');
    }
}
