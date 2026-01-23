<?php

declare(strict_types=1);

namespace Laas\Core;

use Laas\Bootstrap\BootContext;
use Laas\Bootstrap\BootstrapsConfigResolver;
use Laas\Bootstrap\BootstrapsRunner;
use Laas\Core\Bindings\BindingsContext;
use Laas\Core\Bindings\CoreBindings;
use Laas\Core\Bindings\DevBindings;
use Laas\Core\Bindings\DomainBindings;
use Laas\Core\Bindings\ModuleBindings;
use Laas\Core\Container\Container;
use Laas\Database\DatabaseManager;
use Laas\DevTools\DbCollector;
use Laas\DevTools\PerformanceCollector;
use Laas\DevTools\RequestCollector;
use Laas\Events\EventDispatcherInterface;
use Laas\Events\Http\RequestEvent;
use Laas\Events\Http\ResponseEvent;
use Laas\Http\Middleware\ApiMiddleware;
use Laas\Http\Middleware\AuthMiddleware;
use Laas\Http\Middleware\CsrfMiddleware;
use Laas\Http\Middleware\DevToolsMiddleware;
use Laas\Http\Middleware\ErrorHandlerMiddleware;
use Laas\Http\Middleware\HttpLimitsMiddleware;
use Laas\Http\Middleware\MiddlewareQueue;
use Laas\Http\Middleware\RateLimitMiddleware;
use Laas\Http\Middleware\RbacMiddleware;
use Laas\Http\Middleware\ReadOnlyMiddleware;
use Laas\Http\Middleware\SecurityHeadersMiddleware;
use Laas\Http\Middleware\SessionMiddleware;
use Laas\Http\Request;
use Laas\Http\RequestContext;
use Laas\Http\Response;
use Laas\Http\Session\SessionManager;
use Laas\Modules\ModuleCatalog;
use Laas\Modules\ModuleManager;
use Laas\Perf\PerfBudgetEnforcer;
use Laas\Routing\Router;
use Laas\Security\CacheRateLimiterStore;
use Laas\Security\RateLimiter;
use Laas\Security\SecurityHeaders;
use Laas\Support\Cache\CacheInterface;
use Laas\Support\ConfigSanityChecker;
use Laas\Support\LogSpamGuard;
use Laas\Support\RequestScope;
use Laas\View\View;

final class Kernel
{
    private array $config;
    private array $configErrors = [];
    private ?DatabaseManager $databaseManager = null;
    private Container $container;

    public function __construct(private string $rootPath)
    {
        $this->config = $this->loadConfig();
        $this->ensureStorage();
        $this->container = new Container();
        BindingsContext::set($this, $this->config, $this->rootPath);
        $this->registerBindings($this->container);
    }

    public function handle(Request $request): Response
    {
        try {
            $context = (new KernelRequestContextFactory($this->rootPath, $this->config, $this->container))
                ->create($request, $this->database());
            $appConfig = $context->appConfig;
            $bootEnabled = $context->bootEnabled;
            $securityConfig = $context->securityConfig;
            $devtoolsConfig = $context->devtoolsConfig;
            $perfConfig = $context->perfConfig;
            $appDebug = $context->appDebug;
            $router = $context->router;
            $view = $context->view;
            $translator = $context->translator;
            $localeResolver = $context->localeResolver;
            $locale = $context->locale;
            $resolution = $context->localeResolution;
            $sessionFactory = $context->sessionFactory;
            $session = $context->session;
            $authService = $context->authService;
            $authorization = $context->authorization;
            $logger = $context->logger;
            $devtoolsContext = $context->devtoolsContext;
            $requestId = $context->requestId;
            $perfEnabled = $context->perfEnabled;

            $this->container->singleton(View::class, static fn (): View => $view);
            $this->container->singleton(Router::class, static fn (): Router => $router);

            if ($bootEnabled) {
                $bootstraps = (new BootstrapsConfigResolver())->resolve($appConfig, $bootEnabled);
                $runner = new BootstrapsRunner($bootstraps);
                $runner->run(new BootContext(
                    $this->rootPath,
                    $this->container,
                    $this->config,
                    $appDebug
                ));
            }

            $dispatcher = null;
            try {
                $dispatcher = $this->container->get(EventDispatcherInterface::class);
            } catch (\Throwable) {
                $dispatcher = null;
            }
            if ($dispatcher instanceof EventDispatcherInterface) {
                $requestEvent = new RequestEvent($request);
                $dispatcher->dispatch($requestEvent);
                $request = $requestEvent->request;
            }

            $view->setRequest($request);

            try {
                $featureFlags = $this->container->get(FeatureFlagsInterface::class);
                if ($featureFlags instanceof FeatureFlagsInterface) {
                    $devtoolsEnabled = $appDebug;
                    $view->share('admin_features', [
                        'palette' => $devtoolsEnabled && $featureFlags->isEnabled(FeatureFlagsInterface::DEVTOOLS_PALETTE),
                        'blocks_studio' => $devtoolsEnabled && $featureFlags->isEnabled(FeatureFlagsInterface::DEVTOOLS_BLOCKS_STUDIO),
                        'theme_inspector' => $devtoolsEnabled && $featureFlags->isEnabled(FeatureFlagsInterface::DEVTOOLS_THEME_INSPECTOR),
                        'headless_playground' => $devtoolsEnabled && $featureFlags->isEnabled(FeatureFlagsInterface::DEVTOOLS_HEADLESS_PLAYGROUND),
                    ]);
                }
            } catch (Throwable) {
                $view->share('admin_features', [
                    'palette' => false,
                    'blocks_studio' => false,
                    'theme_inspector' => false,
                    'headless_playground' => false,
                ]);
            }

            $adminModulesNav = [];
            if (str_starts_with($request->getPath(), '/admin')) {
                $catalog = new ModuleCatalog(
                    $this->rootPath,
                    $this->database(),
                    $this->config['modules'] ?? null,
                    $this->config['modules_nav'] ?? null
                );
                $adminModulesNav = $catalog->listNav();
                $adminModulesNavSections = $catalog->listNavSections();
            }
            $view->share('admin_modules_nav', $adminModulesNav);
            $view->share('admin_modules_nav_sections', $adminModulesNavSections ?? []);

            $modulesTakeover = $bootEnabled && (bool) ($appConfig['bootstraps_modules_takeover'] ?? false);
            if (!$modulesTakeover) {
                $modules = new ModuleManager($this->config['modules'] ?? [], $view, $this->database(), $this->container);
                $modules->register($router);
            }

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
            $rateLimiterStore = null;
            try {
                $cache = $this->container->get(CacheInterface::class);
                if ($cache instanceof CacheInterface) {
                    $rateLimiterStore = new CacheRateLimiterStore($cache);
                }
            } catch (\Throwable) {
                $rateLimiterStore = null;
            }

            $middleware = new MiddlewareQueue([
                new ErrorHandlerMiddleware($logger, (bool) ($appConfig['debug'] ?? false), $requestId),
                new HttpLimitsMiddleware($httpConfig, $view),
                new SessionMiddleware(new SessionManager($this->rootPath, $securityConfig, $sessionFactory), $securityConfig['session'] ?? null, $logger, $session, $this->rootPath),
                new ApiMiddleware($this->database(), $authorization, $this->config['api'] ?? [], $this->rootPath),
                new ReadOnlyMiddleware((bool) ($appConfig['read_only'] ?? false), $translator, $view),
                new CsrfMiddleware(),
                new RateLimitMiddleware(new RateLimiter($this->rootPath, $rateLimiterStore), $securityConfig),
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

            $devtoolsContext->finalize();
            RequestContext::setMetrics([
                'total_ms' => $devtoolsContext->getDurationMs(),
                'memory_mb' => $devtoolsContext->getMemoryPeakMb(),
                'sql_count' => $devtoolsContext->getDbCount(),
                'sql_unique' => $devtoolsContext->getDbUniqueCount(),
                'sql_dup' => $devtoolsContext->getDbDuplicateCount(),
                'sql_ms' => $devtoolsContext->getDbTotalMs(),
            ]);

            if (!empty($resolution['set_cookie'])) {
                $response = $response->withHeader('Set-Cookie', $localeResolver->cookieHeader($locale));
            }
            if (!$bootEnabled) {
                $response = $response->withHeader('X-Request-Id', $requestId);
            }
            if ($dispatcher instanceof EventDispatcherInterface) {
                $responseEvent = new ResponseEvent($request, $response);
                $dispatcher->dispatch($responseEvent);
                $response = $responseEvent->response;
            }
            return $response;
        } finally {
            RequestScope::reset();
            RequestScope::setRequest(null);
        }
    }

    public function container(): Container
    {
        return $this->container;
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

    private function registerBindings(Container $container): void
    {
        CoreBindings::register($container);
        DomainBindings::register($container);
        ModuleBindings::register($container);
        DevBindings::register($container);
    }

    private function loadConfig(): array
    {
        $configDir = $this->rootPath . '/config';

        $files = [
            'app' => $configDir . '/app.php',
            'admin_features' => $configDir . '/admin_features.php',
            'modules' => $configDir . '/modules.php',
            'modules_nav' => $configDir . '/modules_nav.php',
            'security' => $configDir . '/security.php',
            'compat' => $configDir . '/compat.php',
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
            $this->rootPath . '/storage/cache/data',
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
