<?php
declare(strict_types=1);

namespace Laas\Core;

use Laas\Http\Request;
use Laas\Http\Response;
use Laas\Auth\AuthInterface;
use Laas\Auth\AuthService;
use Laas\Auth\AuthorizationService;
use Laas\Auth\NullAuthService;
use Laas\Http\Middleware\AuthMiddleware;
use Laas\Http\Middleware\RbacMiddleware;
use Laas\Http\Middleware\ErrorHandlerMiddleware;
use Laas\Http\Middleware\CsrfMiddleware;
use Laas\Http\Middleware\DevToolsMiddleware;
use Laas\Http\Middleware\MiddlewareQueue;
use Laas\Http\Middleware\RateLimitMiddleware;
use Laas\Http\Middleware\SecurityHeadersMiddleware;
use Laas\Http\Middleware\SessionMiddleware;
use Laas\Http\Session\SessionManager;
use Laas\Database\DatabaseManager;
use Laas\Database\Repositories\RbacRepository;
use Laas\Database\Repositories\UsersRepository;
use Laas\I18n\LocaleResolver;
use Laas\I18n\Translator;
use Laas\Modules\ModuleManager;
use Laas\Routing\Router;
use Laas\Security\Csrf;
use Laas\Security\RateLimiter;
use Laas\Security\SecurityHeaders;
use Laas\Settings\SettingsProvider;
use Laas\Support\LoggerFactory;
use Laas\DevTools\DevToolsContext;
use Laas\DevTools\RequestCollector;
use Laas\DevTools\PerformanceCollector;
use Laas\DevTools\DbCollector;
use Laas\View\Template\TemplateCompiler;
use Laas\View\Template\TemplateEngine;
use Laas\View\Theme\ThemeManager;
use Laas\View\View;
use RuntimeException;

final class Kernel
{
    private array $config;
    private ?DatabaseManager $databaseManager = null;

    public function __construct(private string $rootPath)
    {
        $this->config = $this->loadConfig();
        $this->ensureStorage();
    }

    public function handle(Request $request): Response
    {
        $router = new Router();

        $appConfig = $this->config['app'] ?? [];
        $securityConfig = $this->config['security'] ?? [];
        $devtoolsConfig = $appConfig['devtools'] ?? [];
        $devtoolsEnabled = (bool) ($appConfig['debug'] ?? false) && (bool) ($devtoolsConfig['enabled'] ?? false);
        $devtoolsConfig['enabled'] = $devtoolsEnabled;

        $requestId = bin2hex(random_bytes(16));
        $devtoolsContext = new DevToolsContext([
            'enabled' => $devtoolsEnabled,
            'debug' => (bool) ($appConfig['debug'] ?? false),
            'env' => (string) ($appConfig['env'] ?? ''),
            'collect_db' => (bool) ($devtoolsConfig['collect_db'] ?? false),
            'collect_request' => (bool) ($devtoolsConfig['collect_request'] ?? false),
            'collect_logs' => (bool) ($devtoolsConfig['collect_logs'] ?? false),
            'request_id' => $requestId,
        ]);

        $this->database()->enableDevTools($devtoolsContext, $devtoolsConfig);

        $settingsProvider = new SettingsProvider(
            $this->database(),
            [
                'site_name' => $appConfig['name'] ?? 'LAAS',
                'default_locale' => $appConfig['default_locale'] ?? 'en',
                'theme' => $appConfig['theme'] ?? 'default',
            ],
            ['site_name', 'default_locale', 'theme']
        );

        $theme = $appConfig['theme'] ?? 'default';
        $themeManager = new ThemeManager($this->rootPath . '/themes', $theme, $settingsProvider);
        $publicTheme = $themeManager->getPublicTheme();

        $localeResolver = new LocaleResolver($appConfig, $settingsProvider);
        $resolution = $localeResolver->resolve($request);
        $locale = $resolution['locale'];

        $translator = new Translator($this->rootPath, $publicTheme, $locale);

        $loggerFactory = new LoggerFactory($this->rootPath);
        $logger = $loggerFactory->create($appConfig);
        if ($logger instanceof \Monolog\Logger) {
            $logger->pushProcessor(static function (array $record) use ($requestId): array {
                $record['extra']['request_id'] = $requestId;
                return $record;
            });
        }

        $authService = $this->createAuthService($logger);
        $authorization = $this->createAuthorizationService();

        $templateEngine = new TemplateEngine(
            $themeManager,
            new TemplateCompiler(),
            $this->rootPath . '/storage/cache/templates',
            (bool) ($appConfig['debug'] ?? false)
        );
        $view = new View(
            $themeManager,
            $templateEngine,
            $translator,
            $locale,
            $appConfig,
            $authService,
            $settingsProvider,
            $this->rootPath . '/storage/cache/templates',
            $this->database()
        );
        $view->setRequest($request);

        $modules = new ModuleManager($this->config['modules'] ?? [], $view, $this->database());
        $modules->register($router);

        $collectors = [
            new PerformanceCollector(),
            new DbCollector(),
        ];
        if (!empty($devtoolsConfig['collect_request'])) {
            $collectors[] = new RequestCollector();
        }

        $middleware = new MiddlewareQueue([
            new ErrorHandlerMiddleware($logger, (bool) ($appConfig['debug'] ?? false), $requestId),
            new SessionMiddleware(new SessionManager($this->rootPath, $securityConfig)),
            new CsrfMiddleware(new Csrf()),
            new RateLimitMiddleware(new RateLimiter($this->rootPath), $securityConfig),
            new SecurityHeadersMiddleware(new SecurityHeaders($securityConfig)),
            new AuthMiddleware($authService),
            new RbacMiddleware($authService, $authorization, $view),
            new DevToolsMiddleware($devtoolsContext, $devtoolsConfig, $authService, $authorization, $view, $this->database(), $collectors),
        ]);

        $response = $middleware->dispatch($request, static function (Request $request) use ($router): Response {
            return $router->dispatch($request);
        });

        if (!empty($resolution['set_cookie'])) {
            $response = $response->withHeader('Set-Cookie', $localeResolver->cookieHeader($locale));
        }
        return $response->withHeader('X-Request-Id', $requestId);
    }

    private function createAuthService(\Psr\Log\LoggerInterface $logger): AuthInterface
    {
        try {
            $usersRepository = new UsersRepository($this->database()->pdo());
            return new AuthService($usersRepository, $logger);
        } catch (\Throwable) {
            return new NullAuthService();
        }
    }

    private function createAuthorizationService(): AuthorizationService
    {
        try {
            if (!$this->database()->healthCheck()) {
                return new AuthorizationService(null);
            }

            return new AuthorizationService(new RbacRepository($this->database()->pdo()));
        } catch (\Throwable) {
            return new AuthorizationService(null);
        }
    }

    public function database(): DatabaseManager
    {
        if ($this->databaseManager !== null) {
            return $this->databaseManager;
        }

        $dbConfig = $this->config['database'] ?? [];
        $this->databaseManager = new DatabaseManager($dbConfig);

        return $this->databaseManager;
    }

    private function loadConfig(): array
    {
        $configDir = $this->rootPath . '/config';

        $files = [
            'app' => $configDir . '/app.php',
            'modules' => $configDir . '/modules.php',
            'security' => $configDir . '/security.php',
            'database' => $configDir . '/database.php',
            'media' => $configDir . '/media.php',
        ];

        $config = [];
        foreach ($files as $key => $path) {
            if (!is_file($path)) {
                throw new RuntimeException('Missing config file: ' . $path);
            }

            $config[$key] = require $path;
            if (!is_array($config[$key])) {
                throw new RuntimeException('Config must return array: ' . $path);
            }
        }

        return $config;
    }

    private function ensureStorage(): void
    {
        $paths = [
            $this->rootPath . '/storage/logs',
            $this->rootPath . '/storage/sessions',
            $this->rootPath . '/storage/cache',
            $this->rootPath . '/storage/cache/templates',
            $this->rootPath . '/storage/cache/ratelimit',
            $this->rootPath . '/storage/uploads',
        ];

        foreach ($paths as $path) {
            if (!is_dir($path)) {
                mkdir($path, 0775, true);
            }
        }
    }
}
