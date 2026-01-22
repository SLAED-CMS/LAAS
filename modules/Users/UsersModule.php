<?php

declare(strict_types=1);

namespace Laas\Modules\Users;

use Laas\Auth\AuthInterface;
use Laas\Auth\AuthService;
use Laas\Auth\NullAuthService;
use Laas\Auth\TotpService;
use Laas\Core\Container\Container;
use Laas\Database\DatabaseManager;
use Laas\Database\Repositories\UsersRepository;
use Laas\Domain\Users\UsersReadServiceInterface;
use Laas\Domain\Users\UsersService;
use Laas\Domain\Users\UsersWriteServiceInterface;
use Laas\Http\Request;
use Laas\Http\Response;
use Laas\Modules\ModuleInterface;
use Laas\Modules\Users\Controller\AuthController;
use Laas\Modules\Users\Controller\PasswordResetController;
use Laas\Modules\Users\Controller\TwoFactorController;
use Laas\Routing\RouteHandlerSpec;
use Laas\Routing\Router;
use Laas\Security\CacheRateLimiterStore;
use Laas\Security\RateLimiter;
use Laas\Security\RateLimiterStoreInterface;
use Laas\Session\SessionInterface;
use Laas\Support\Cache\CacheInterface;
use Laas\Support\Mail\PhpMailer;
use Laas\View\View;
use Psr\Log\NullLogger;

final class UsersModule implements ModuleInterface
{
    public function __construct(
        private View $view,
        private ?DatabaseManager $db = null,
        private ?Container $container = null
    ) {
    }

    public function registerRoutes(Router $router): void
    {
        $contextKey = self::class;
        $router->registerContext($contextKey, [
            'view' => $this->view,
            'container' => $this->container,
            'module' => $this,
        ]);

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

            $router->addRoute($method, $path, RouteHandlerSpec::module($contextKey, $class, $action));
        }
    }

    /**
     * @param array<string, string> $vars
     */
    public function dispatchRoute(string $class, string $action, Request $request, array $vars = []): Response
    {
        $controller = $this->createController($class, $request->session());
        return $controller->{$action}($request);
    }

    private function createController(string $class, SessionInterface $session): object
    {
        $auth = $this->createAuthService($session);

        $usersReadService = $this->resolveUsersReadService();
        $usersWriteService = $this->resolveUsersWriteService();
        $logger = new NullLogger();

        return match ($class) {
            AuthController::class => new AuthController(
                $this->view,
                $auth,
                $usersReadService,
                $usersWriteService,
                new TotpService()
            ),
            PasswordResetController::class => new PasswordResetController(
                $this->view,
                $usersReadService,
                $usersWriteService,
                new PhpMailer(null, $logger),
                new RateLimiter(dirname(__DIR__, 2), $this->rateLimiterStore()),
                dirname(__DIR__, 2),
                $logger
            ),
            TwoFactorController::class => new TwoFactorController(
                $this->view,
                $auth,
                $usersReadService,
                $usersWriteService,
                new TotpService(),
                $logger
            ),
            default => new $class($this->view, $auth),
        };
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

    private function resolveUsersReadService(): ?UsersReadServiceInterface
    {
        if ($this->container !== null) {
            try {
                $service = $this->container->get(UsersReadServiceInterface::class);
                if ($service instanceof UsersReadServiceInterface) {
                    return $service;
                }
            } catch (\Throwable) {
                return null;
            }
        }

        if ($this->db === null) {
            return null;
        }

        return new UsersService($this->db);
    }

    private function resolveUsersWriteService(): ?UsersWriteServiceInterface
    {
        if ($this->container !== null) {
            try {
                $service = $this->container->get(UsersWriteServiceInterface::class);
                if ($service instanceof UsersWriteServiceInterface) {
                    return $service;
                }
            } catch (\Throwable) {
                return null;
            }
        }

        if ($this->db === null) {
            return null;
        }

        return new UsersService($this->db);
    }

    private function rateLimiterStore(): ?RateLimiterStoreInterface
    {
        if ($this->container === null) {
            return null;
        }

        try {
            $cache = $this->container->get(CacheInterface::class);
            if ($cache instanceof CacheInterface) {
                return new CacheRateLimiterStore($cache);
            }
        } catch (\Throwable) {
            return null;
        }

        return null;
    }
}
