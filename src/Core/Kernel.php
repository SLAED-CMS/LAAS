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
use Laas\Modules\ModuleCatalog;
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
use Laas\Core\Container\Container;
use Laas\Domain\AdminSearch\AdminSearchService;
use Laas\Domain\AdminSearch\AdminSearchServiceInterface;
use Laas\Domain\Audit\AuditLogService;
use Laas\Domain\Audit\AuditLogServiceInterface;
use Laas\Domain\ApiTokens\ApiTokensService;
use Laas\Domain\ApiTokens\ApiTokensReadServiceInterface;
use Laas\Domain\ApiTokens\ApiTokensServiceInterface;
use Laas\Domain\ApiTokens\ApiTokensWriteServiceInterface;
use Laas\Domain\Media\MediaService;
use Laas\Domain\Media\MediaReadServiceInterface;
use Laas\Domain\Media\MediaServiceInterface;
use Laas\Domain\Media\MediaWriteServiceInterface;
use Laas\Domain\Modules\ModulesService;
use Laas\Domain\Modules\ModulesServiceInterface;
use Laas\Domain\Menus\MenusService;
use Laas\Domain\Menus\MenusReadServiceInterface;
use Laas\Domain\Menus\MenusServiceInterface;
use Laas\Domain\Menus\MenusWriteServiceInterface;
use Laas\Domain\Ops\OpsService;
use Laas\Domain\Ops\OpsReadServiceInterface;
use Laas\Domain\Ops\OpsServiceInterface;
use Laas\Domain\Pages\PagesService;
use Laas\Domain\Pages\PagesReadServiceInterface;
use Laas\Domain\Pages\PagesServiceInterface;
use Laas\Domain\Pages\PagesWriteServiceInterface;
use Laas\Domain\Rbac\RbacService;
use Laas\Domain\Rbac\RbacServiceInterface;
use Laas\Domain\Security\SecurityReportsService;
use Laas\Domain\Security\SecurityReportsReadServiceInterface;
use Laas\Domain\Security\SecurityReportsServiceInterface;
use Laas\Domain\Security\SecurityReportsWriteServiceInterface;
use Laas\Domain\Settings\SettingsService;
use Laas\Domain\Settings\SettingsReadServiceInterface;
use Laas\Domain\Settings\SettingsServiceInterface;
use Laas\Domain\Settings\SettingsWriteServiceInterface;
use Laas\Domain\Users\UsersService;
use Laas\Domain\Users\UsersReadServiceInterface;
use Laas\Domain\Users\UsersServiceInterface;
use Laas\Domain\Users\UsersWriteServiceInterface;
use Laas\View\AssetManager;
use Laas\View\Template\TemplateCompiler;
use Laas\View\Template\TemplateEngine;
use Laas\View\Theme\ThemeManager;
use Laas\View\View;
use Laas\Content\Blocks\BlockRegistry;

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
        $this->registerContainerServices();
    }

    public function handle(Request $request): Response
    {
        RequestScope::reset();
        RequestScope::setRequest($request);
        RequestScope::set('blocks.registry', $this->container->get(BlockRegistry::class));

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
        if ($themeValidation->hasWarnings()) {
            foreach ($themeValidation->getWarnings() as $warning) {
                $logger->notice('Theme compat warning', [
                    'theme' => $publicTheme,
                    'code' => $warning['code'] ?? '',
                    'file' => $warning['file'] ?? '',
                    'message' => $warning['message'] ?? '',
                ]);
                if ((bool) ($appConfig['debug'] ?? false)) {
                    $devtoolsContext->addWarning('theme_compat', (string) ($warning['message'] ?? 'Theme compat warning'));
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

        $modules = new ModuleManager($this->config['modules'] ?? [], $view, $this->database(), $this->container);
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

    private function registerContainerServices(): void
    {
        $config = $this->config;
        $rootPath = $this->rootPath;

        $this->container->singleton('config', static function () use ($config): array {
            return $config;
        });

        $this->container->singleton('db', function (): DatabaseManager {
            return $this->database();
        });

        $this->container->singleton('translator', static function () use ($config, $rootPath): Translator {
            $appConfig = $config['app'] ?? [];
            $theme = $appConfig['theme'] ?? 'default';
            $locale = $appConfig['default_locale'] ?? 'en';

            return new Translator($rootPath, $theme, $locale);
        });

        $this->container->singleton(PagesServiceInterface::class, function (): PagesServiceInterface {
            return new PagesService($this->database());
        });

        $this->container->singleton(PagesReadServiceInterface::class, function (): PagesReadServiceInterface {
            return $this->container->get(PagesServiceInterface::class);
        });

        $this->container->singleton(PagesWriteServiceInterface::class, function (): PagesWriteServiceInterface {
            return $this->container->get(PagesServiceInterface::class);
        });

        $this->container->singleton(MediaServiceInterface::class, function () use ($config, $rootPath): MediaServiceInterface {
            return new MediaService(
                $this->database(),
                $config['media'] ?? [],
                $rootPath
            );
        });

        $this->container->singleton(MediaReadServiceInterface::class, function (): MediaReadServiceInterface {
            return $this->container->get(MediaServiceInterface::class);
        });

        $this->container->singleton(MediaWriteServiceInterface::class, function (): MediaWriteServiceInterface {
            return $this->container->get(MediaServiceInterface::class);
        });

        $this->container->singleton(SecurityReportsServiceInterface::class, function (): SecurityReportsServiceInterface {
            return new SecurityReportsService($this->database());
        });

        $this->container->singleton(SecurityReportsReadServiceInterface::class, function (): SecurityReportsReadServiceInterface {
            return $this->container->get(SecurityReportsServiceInterface::class);
        });

        $this->container->singleton(SecurityReportsWriteServiceInterface::class, function (): SecurityReportsWriteServiceInterface {
            return $this->container->get(SecurityReportsServiceInterface::class);
        });

        $this->container->singleton(ApiTokensServiceInterface::class, function () use ($config, $rootPath): ApiTokensServiceInterface {
            return new ApiTokensService(
                $this->database(),
                $config['api'] ?? [],
                $rootPath
            );
        });

        $this->container->singleton(ApiTokensReadServiceInterface::class, function (): ApiTokensReadServiceInterface {
            return $this->container->get(ApiTokensServiceInterface::class);
        });

        $this->container->singleton(ApiTokensWriteServiceInterface::class, function (): ApiTokensWriteServiceInterface {
            return $this->container->get(ApiTokensServiceInterface::class);
        });

        $this->container->singleton(OpsServiceInterface::class, function () use ($config, $rootPath): OpsServiceInterface {
            $securityReports = $this->container->get(SecurityReportsServiceInterface::class);

            return new OpsService(
                $this->database(),
                $config,
                $rootPath,
                $securityReports
            );
        });

        $this->container->singleton(OpsReadServiceInterface::class, function (): OpsReadServiceInterface {
            return $this->container->get(OpsServiceInterface::class);
        });

        $this->container->singleton(AdminSearchServiceInterface::class, function () use ($config, $rootPath): AdminSearchServiceInterface {
            $pages = $this->container->get(PagesServiceInterface::class);
            $media = $this->container->get(MediaServiceInterface::class);
            $users = $this->container->get(UsersServiceInterface::class);
            $menus = $this->container->get(MenusServiceInterface::class);
            $securityReports = $this->container->get(SecurityReportsServiceInterface::class);
            $moduleCatalog = new ModuleCatalog(
                $rootPath,
                $this->database(),
                $config['modules'] ?? null,
                $config['modules_nav'] ?? null
            );

            return new AdminSearchService(
                $pages,
                $media,
                $users,
                $menus,
                $moduleCatalog,
                $securityReports
            );
        });

        $this->container->singleton(RbacServiceInterface::class, function (): RbacServiceInterface {
            return new RbacService($this->database());
        });

        $this->container->singleton(AuditLogServiceInterface::class, function (): AuditLogServiceInterface {
            return new AuditLogService($this->database());
        });

        $this->container->singleton(UsersServiceInterface::class, function (): UsersServiceInterface {
            return new UsersService($this->database());
        });

        $this->container->singleton(UsersReadServiceInterface::class, function (): UsersReadServiceInterface {
            return $this->container->get(UsersServiceInterface::class);
        });

        $this->container->singleton(UsersWriteServiceInterface::class, function (): UsersWriteServiceInterface {
            return $this->container->get(UsersServiceInterface::class);
        });

        $this->container->singleton(MenusServiceInterface::class, function (): MenusServiceInterface {
            return new MenusService($this->database());
        });

        $this->container->singleton(MenusReadServiceInterface::class, function (): MenusReadServiceInterface {
            return $this->container->get(MenusServiceInterface::class);
        });

        $this->container->singleton(MenusWriteServiceInterface::class, function (): MenusWriteServiceInterface {
            return $this->container->get(MenusServiceInterface::class);
        });

        $this->container->singleton(ModulesServiceInterface::class, function () use ($config, $rootPath): ModulesServiceInterface {
            return new ModulesService($this->database(), $config, $rootPath);
        });

        $this->container->singleton(SettingsServiceInterface::class, function (): SettingsServiceInterface {
            return new SettingsService($this->database());
        });

        $this->container->singleton(SettingsReadServiceInterface::class, function (): SettingsReadServiceInterface {
            return $this->container->get(SettingsServiceInterface::class);
        });

        $this->container->singleton(SettingsWriteServiceInterface::class, function (): SettingsWriteServiceInterface {
            return $this->container->get(SettingsServiceInterface::class);
        });

        $this->container->singleton(BlockRegistry::class, static function (): BlockRegistry {
            return BlockRegistry::default();
        });
    }

    private function loadConfig(): array
    {
        $configDir = $this->rootPath . '/config';

        $files = [
            'app' => $configDir . '/app.php',
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
