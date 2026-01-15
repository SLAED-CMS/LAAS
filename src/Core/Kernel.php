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
use Laas\Http\Middleware\ApiMiddleware;
use Laas\Http\Middleware\RbacMiddleware;
use Laas\Http\Middleware\ErrorHandlerMiddleware;
use Laas\Http\Middleware\CsrfMiddleware;
use Laas\Http\Middleware\DevToolsMiddleware;
use Laas\Http\Middleware\HttpLimitsMiddleware;
use Laas\Http\Middleware\MiddlewareQueue;
use Laas\Http\Middleware\RateLimitMiddleware;
use Laas\Http\Middleware\ReadOnlyMiddleware;
use Laas\Http\Middleware\SecurityHeadersMiddleware;
use Laas\Http\Middleware\SessionMiddleware;
use Laas\Http\Session\SessionManager;
use Laas\Database\DatabaseManager;
use Laas\Database\DbProfileCollector;
use Laas\Database\Repositories\RbacRepository;
use Laas\Database\Repositories\UsersRepository;
use Laas\I18n\LocaleResolver;
use Laas\I18n\Translator;
use Laas\Modules\ModuleManager;
use Laas\Routing\Router;
use Laas\Security\RateLimiter;
use Laas\Security\SecurityHeaders;
use Laas\Session\SessionFactory;
use Laas\Session\SessionInterface;
use Laas\Settings\SettingsProvider;
use Laas\Support\LoggerFactory;
use Laas\Support\ConfigSanityChecker;
use Laas\Support\LogSpamGuard;
use Laas\Support\RequestScope;
use Laas\DevTools\DevToolsContext;
use Laas\DevTools\RequestCollector;
use Laas\DevTools\PerformanceCollector;
use Laas\DevTools\DbCollector;
use Laas\Theme\ThemeValidator;
use Laas\Perf\PerfBudgetEnforcer;
use Laas\Assets\AssetsManager;
use Laas\View\AssetManager;
use Laas\View\Template\TemplateCompiler;
use Laas\View\Template\TemplateEngine;
use Laas\View\Theme\ThemeManager;
use Laas\View\View;

final class Kernel
{
    private array $config;
    private array $configErrors = [];
    private ?DatabaseManager $databaseManager = null;

    public function __construct(private string $rootPath)
    {
        $this->config = $this->loadConfig();
        $this->ensureStorage();
    }

    public function handle(Request $request): Response
    {
        RequestScope::reset();
        RequestScope::setRequest($request);

        try {
        $router = new Router();

        $appConfig = $this->config['app'] ?? [];
        $securityConfig = $this->config['security'] ?? [];
        $devtoolsConfig = array_merge($this->config['devtools'] ?? [], $appConfig['devtools'] ?? []);
        $perfConfig = $this->config['perf'] ?? [];
        $env = strtolower((string) ($appConfig['env'] ?? ''));
        $appDebug = (bool) ($appConfig['debug'] ?? false);
        $devtoolsEnabled = $appDebug && (bool) ($devtoolsConfig['enabled'] ?? false);
        $perfEnabled = (bool) ($perfConfig['enabled'] ?? false);
        $collectDb = (bool) ($devtoolsConfig['collect_db'] ?? false);
        if ($perfEnabled) {
            $collectDb = true;
        }
        if ($env === 'prod') {
            $devtoolsEnabled = false;
            $devtoolsConfig['enabled'] = false;
            $devtoolsConfig['collect_request'] = false;
            $devtoolsConfig['collect_logs'] = false;
        }
        $devtoolsConfig['collect_db'] = $collectDb;
        $devtoolsConfig['enabled'] = $devtoolsEnabled;
        $storeSql = $appDebug
            && (bool) ($devtoolsConfig['show_secrets'] ?? false)
            && (bool) ($appConfig['db_profile']['store_sql'] ?? false);

        $requestId = $this->resolveRequestId($request);
        $devtoolsContext = new DevToolsContext([
            'enabled' => $devtoolsEnabled,
            'debug' => $appDebug,
            'env' => (string) ($appConfig['env'] ?? ''),
            'is_dev' => $env !== 'prod',
            'root_path' => $this->rootPath,
            'budgets' => $devtoolsConfig['budgets'] ?? [],
            'show_secrets' => (bool) ($devtoolsConfig['show_secrets'] ?? false),
            'collect_db' => (bool) ($devtoolsConfig['collect_db'] ?? false),
            'collect_request' => (bool) ($devtoolsConfig['collect_request'] ?? false),
            'collect_logs' => (bool) ($devtoolsConfig['collect_logs'] ?? false),
            'store_sql' => $storeSql,
            'request_id' => $requestId,
        ]);
        RequestScope::set('devtools.context', $devtoolsContext);

        $dbProfileCollector = new DbProfileCollector();
        RequestScope::set('db.profile', $dbProfileCollector);

        $this->database()->enableDevTools($devtoolsContext, $devtoolsConfig);
        $this->database()->enableDbProfiling($dbProfileCollector);

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
            $logger->pushProcessor(static function ($record) use ($requestId) {
                if ($record instanceof \Monolog\LogRecord) {
                    return $record->with(extra: array_merge($record->extra, [
                        'request_id' => $requestId,
                    ]));
                }
                if (is_array($record)) {
                    $record['extra']['request_id'] = $requestId;
                    return $record;
                }
                return $record;
            });
        }

        $themeValidator = new ThemeValidator($this->rootPath . '/themes');
        $themeValidation = $themeValidator->validateTheme($publicTheme);
        if ($themeValidation->hasViolations()) {
            foreach ($themeValidation->getViolations() as $violation) {
                $logger->warning('Theme validation warning', [
                    'theme' => $publicTheme,
                    'code' => $violation['code'] ?? '',
                    'file' => $violation['file'] ?? '',
                    'message' => $violation['message'] ?? '',
                ]);
                if ((bool) ($appConfig['debug'] ?? false)) {
                    $devtoolsContext->addWarning('theme_validation', (string) ($violation['message'] ?? 'Theme validation warning'));
                }
            }
        }

        $sessionFactory = new SessionFactory($securityConfig['session'] ?? [], $logger, $this->rootPath);
        $session = $sessionFactory->create();
        $authService = $this->createAuthService($logger, $session);
        $authorization = $this->createAuthorizationService();

        $templateRawMode = (string) ($securityConfig['template']['raw_mode']
            ?? $securityConfig['template_raw_mode']
            ?? 'escape');
        $templateEngine = new TemplateEngine(
            $themeManager,
            new TemplateCompiler(),
            $this->rootPath . '/storage/cache/templates',
            (bool) ($appConfig['debug'] ?? false),
            $templateRawMode
        );
        $assetManager = new AssetManager($this->config['assets'] ?? []);
        $assetsManager = new AssetsManager($this->config['assets'] ?? []);
        $assets = $assetsManager->all();
        $view = new View(
            $themeManager,
            $templateEngine,
            $translator,
            $locale,
            $appConfig,
            $assetManager,
            $authService,
            $settingsProvider,
            $this->rootPath . '/storage/cache/templates',
            $this->database(),
            $assets,
            $templateRawMode
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

        $configErrors = $this->configErrors;
        $checker = new ConfigSanityChecker();
        $sanityErrors = $checker->check($this->config);
        if ($sanityErrors !== []) {
            $configErrors = array_merge($configErrors, $sanityErrors);
        }
        if ($configErrors !== []) {
            $guard = new LogSpamGuard($this->rootPath);
            foreach ($configErrors as $error) {
                $guard->logOnce($logger, 'config:' . $error, 'Config sanity check failed', [
                    'error' => $error,
                ]);
            }
            if ($request->getPath() !== '/health') {
                return new Response('Error', 500, [
                    'Content-Type' => 'text/plain; charset=utf-8',
                ]);
            }
        }

        $httpConfig = $this->config['http'] ?? [];

        $middleware = new MiddlewareQueue([
            new ErrorHandlerMiddleware($logger, (bool) ($appConfig['debug'] ?? false), $requestId),
            new HttpLimitsMiddleware($httpConfig, $view),
            new SessionMiddleware(new SessionManager($this->rootPath, $securityConfig, $sessionFactory), $securityConfig['session'] ?? null, $logger, $session, $this->rootPath),
            new ApiMiddleware($this->database(), $authorization, $this->config['api'] ?? [], $this->rootPath),
            new ReadOnlyMiddleware((bool) ($appConfig['read_only'] ?? false), $translator, $view),
            new CsrfMiddleware(),
            new RateLimitMiddleware(new RateLimiter($this->rootPath), $securityConfig),
            new SecurityHeadersMiddleware(new SecurityHeaders($securityConfig)),
            new AuthMiddleware($authService),
            new RbacMiddleware($authService, $authorization),
            new DevToolsMiddleware($devtoolsContext, $devtoolsConfig, $authService, $authorization, $view, $this->database(), $collectors),
        ]);

        $response = $middleware->dispatch($request, static function (Request $request) use ($router): Response {
            return $router->dispatch($request);
        });

        if ($perfEnabled) {
            $enforcer = new PerfBudgetEnforcer($perfConfig);
            $result = $enforcer->evaluate($devtoolsContext);
            if ($result->hasViolations()) {
                foreach ($result->getViolations() as $violation) {
                    $logger->warning('Performance budget warning', [
                        'metric' => $violation['metric'] ?? '',
                        'value' => $violation['value'] ?? null,
                        'threshold' => $violation['threshold'] ?? null,
                        'level' => $violation['level'] ?? '',
                    ]);
                }
            }
            if ($result->isHard() && (bool) ($perfConfig['hard_fail'] ?? false)) {
                return $enforcer->buildOverBudgetResponse($request);
            }
        }

        if (!empty($resolution['set_cookie'])) {
            $response = $response->withHeader('Set-Cookie', $localeResolver->cookieHeader($locale));
        }
        return $response->withHeader('X-Request-Id', $requestId);
        } finally {
            RequestScope::reset();
            RequestScope::setRequest(null);
        }
    }

    private function createAuthService(\Psr\Log\LoggerInterface $logger, SessionInterface $session): AuthInterface
    {
        try {
            $usersRepository = new UsersRepository($this->database()->pdo());
            return new AuthService($usersRepository, $session, $logger);
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
            'storage' => $configDir . '/storage.php',
            'api' => $configDir . '/api.php',
            'devtools' => $configDir . '/devtools.php',
            'perf' => $configDir . '/perf.php',
            'cache' => $configDir . '/cache.php',
            'assets' => $configDir . '/assets.php',
            'http' => $configDir . '/http.php',
        ];

        $config = [];
        foreach ($files as $key => $path) {
            if (!is_file($path)) {
                $this->configErrors[] = 'Missing config file: ' . $path;
                $config[$key] = [];
                continue;
            }

            $config[$key] = require $path;
            if (!is_array($config[$key])) {
                $this->configErrors[] = 'Config must return array: ' . $path;
                $config[$key] = [];
            }
            if ($key === 'security') {
                $localPath = $configDir . '/security.local.php';
                if (is_file($localPath)) {
                    $localConfig = require $localPath;
                    if (is_array($localConfig)) {
                        $config[$key] = array_replace($config[$key], $localConfig);
                    } else {
                        $this->configErrors[] = 'Config must return array: ' . $localPath;
                    }
                }
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

    private function resolveRequestId(Request $request): string
    {
        $candidate = (string) ($request->getHeader('x-request-id') ?? '');
        if ($this->isValidRequestId($candidate)) {
            return $candidate;
        }

        return bin2hex(random_bytes(16));
    }

    private function isValidRequestId(string $value): bool
    {
        if ($value === '') {
            return false;
        }

        return preg_match('/^[a-zA-Z0-9._-]{8,64}$/', $value) === 1;
    }
}
