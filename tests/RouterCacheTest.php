<?php
declare(strict_types=1);

use Laas\Http\Request;
use Laas\Http\Response;
use Laas\Routing\Router;
use PHPUnit\Framework\TestCase;

final class RouterCacheTest extends TestCase
{
    public function testRouteCacheCreatedOnDispatch(): void
    {
        $cacheDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'laas-route-cache-test-' . bin2hex(random_bytes(4));
        if (!is_dir($cacheDir)) {
            mkdir($cacheDir, 0775, true);
        }

        $router = new Router($cacheDir, true);
        $router->addRoute('GET', '/ping', [RouterCacheTestHandler::class, 'handle']);

        $request = new Request('GET', '/ping', [], [], [], '');
        $router->dispatch($request);

        $cacheFile = $cacheDir . DIRECTORY_SEPARATOR . 'routes.php';
        $fingerprintFile = $cacheDir . DIRECTORY_SEPARATOR . 'routes.sha1';
        $this->assertFileExists($cacheFile);
        $this->assertFileExists($fingerprintFile);

        @unlink($cacheFile);
        @unlink($fingerprintFile);
        @rmdir($cacheDir);
    }
}

final class RouterCacheTestHandler
{
    /**
     * @param array<string, string> $vars
     */
    public static function handle(Request $request, array $vars = []): Response
    {
        return new Response('ok', 200);
    }
}
