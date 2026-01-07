<?php
declare(strict_types=1);

namespace Laas\Modules\Users;

use Laas\Auth\AuthInterface;
use Laas\Auth\AuthService;
use Laas\Auth\NullAuthService;
use Laas\Auth\TotpService;
use Laas\Database\DatabaseManager;
use Laas\Database\Repositories\PasswordResetRepository;
use Laas\Database\Repositories\UsersRepository;
use Laas\Modules\ModuleInterface;
use Laas\Modules\Users\Controller\AuthController;
use Laas\Modules\Users\Controller\PasswordResetController;
use Laas\Modules\Users\Controller\TwoFactorController;
use Laas\Routing\Router;
use Laas\Security\RateLimiter;
use Laas\Session\SessionInterface;
use Laas\Support\Mail\MailerInterface;
use Laas\Support\Mail\PhpMailer;
use Laas\View\View;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

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
                $controller = $this->createController($class, $request->session());
                return $controller->{$action}($request);
            });
        }
    }

    private function createController(string $class, SessionInterface $session): object
    {
        $auth = $this->createAuthService($session);

        if ($this->db === null) {
            return new $class($this->view, $auth);
        }

        $pdo = $this->db->pdo();
        $usersRepo = new UsersRepository($pdo);
        $logger = new NullLogger();

        return match ($class) {
            AuthController::class => new AuthController(
                $this->view,
                $auth,
                $usersRepo,
                new TotpService()
            ),
            PasswordResetController::class => new PasswordResetController(
                $this->view,
                $usersRepo,
                new PasswordResetRepository($pdo),
                new PhpMailer(null, $logger),
                new RateLimiter(dirname(__DIR__, 2)),
                dirname(__DIR__, 2),
                $logger
            ),
            TwoFactorController::class => new TwoFactorController(
                $this->view,
                $auth,
                $usersRepo,
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
}
