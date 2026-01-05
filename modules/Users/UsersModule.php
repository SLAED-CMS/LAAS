<?php
declare(strict_types=1);

namespace Laas\Modules\Users;

use Laas\Auth\AuthInterface;
use Laas\Auth\AuthService;
use Laas\Auth\NullAuthService;
use Laas\Database\DatabaseManager;
use Laas\Database\Repositories\UsersRepository;
use Laas\Modules\ModuleInterface;
use Laas\Routing\Router;
use Laas\Session\SessionInterface;
use Laas\View\View;

final class UsersModule implements ModuleInterface
{
    public function __construct(
        private View $view,
        private ?DatabaseManager $db = null
    ) {
    }

    public function registerRoutes(Router $router): void
    {
        $routes = require __DIR__ . '/routes.php';
        foreach ($routes as $route) {
            [$method, $path, $handler] = $route;
            if (!is_array($handler) || count($handler) !== 2) {
                continue;
            }

            [$class, $action] = $handler;
            if (!is_string($class) || !is_string($action)) {
                continue;
            }

            $router->addRoute($method, $path, function ($request, array $vars = []) use ($class, $action) {
                $auth = $this->createAuthService($request->session());
                $controller = new $class($this->view, $auth);
                return $controller->{$action}($request);
            });
        }
    }

    private function createAuthService(SessionInterface $session): AuthInterface
    {
        if ($this->db === null) {
            return new NullAuthService();
        }

        try {
            return new AuthService(new UsersRepository($this->db->pdo()), $session);
        } catch (\Throwable) {
            return new NullAuthService();
        }
    }
}
