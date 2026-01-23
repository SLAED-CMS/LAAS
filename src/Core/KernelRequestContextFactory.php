<?php

declare(strict_types=1);

namespace Laas\Core;

use Laas\Assets\AssetsManager;
use Laas\Auth\AuthInterface;
use Laas\Auth\AuthorizationService;
use Laas\Auth\AuthService;
use Laas\Auth\NullAuthService;
use Laas\Content\Blocks\BlockRegistry;
use Laas\Core\Container\Container;
use Laas\Database\DatabaseManager;
use Laas\Database\DbProfileCollector;
use Laas\Database\Repositories\RbacRepository;
use Laas\Database\Repositories\UsersRepository;
use Laas\DevTools\DevToolsContext;
use Laas\Http\Request;
use Laas\Http\RequestContext;
use Laas\Http\RequestId;
use Laas\I18n\LocaleResolver;
use Laas\I18n\Translator;
use Laas\Routing\Router;
use Laas\Session\SessionFactory;
use Laas\Session\SessionInterface;
use Laas\Settings\SettingsProvider;
use Laas\Support\LoggerFactory;
use Laas\Support\RequestScope;
use Laas\Theme\TemplateResolver;
use Laas\Theme\ThemeInterface;
use Laas\Theme\ThemeRegistry;
use Laas\Theme\ThemeValidator;
use Laas\View\AssetManager;
use Laas\View\Template\TemplateCompiler;
use Laas\View\Template\TemplateEngine;
use Laas\View\Theme\ThemeManager;
use Laas\View\View;
use Psr\Log\LoggerInterface;

final class KernelRequestContextFactory
{
    public function __construct(
        private string $rootPath,
        /** @var array<string, mixed> */
        private array $config,
        private Container $container
    ) {
    }

    public function create(Request $request, DatabaseManager $database): KernelRequestContext
    {
        RequestScope::reset();
        RequestScope::setRequest($request);
        RequestScope::set('blocks.registry', $this->container->get(BlockRegistry::class));
        RequestScope::set('devtools.paths', [
            '/admin/themes',
            '/admin/themes/validate',
            '/admin/headless-playground',
            '/admin/headless-playground/fetch',
            '/admin/search/palette',
        ]);

        $appConfig = $this->config['app'] ?? [];
        $bootEnabled = (bool) ($appConfig['bootstraps_enabled'] ?? false);
        $securityConfig = $this->config['security'] ?? [];
        $devtoolsConfig = array_merge([
            'enabled' => false,
            'collect_db' => false,
            'collect_request' => false,
            'collect_logs' => false,
            'show_secrets' => false,
            'budgets' => [],
        ], $this->config['devtools'] ?? [], $appConfig['devtools'] ?? []);
        $perfConfig = $this->config['perf'] ?? [];
        $env = strtolower((string) ($appConfig['env'] ?? ''));
        $appDebug = (bool) ($appConfig['debug'] ?? false);
        $router = new Router($this->rootPath . '/storage/cache', $appDebug && $env !== 'prod');

        $devtoolsEnabled = $appDebug && (bool) $devtoolsConfig['enabled'];
        $perfEnabled = (bool) ($perfConfig['enabled'] ?? false);
        $collectDb = (bool) $devtoolsConfig['collect_db'];
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
            && (bool) $devtoolsConfig['show_secrets']
            && (bool) ($appConfig['db_profile']['store_sql'] ?? false);

        $requestId = $bootEnabled ? RequestId::fromRequest($request) : $this->resolveRequestId($request);
        $devtoolsContext = new DevToolsContext([
            'enabled' => $devtoolsEnabled,
            'debug' => $appDebug,
            'env' => (string) ($appConfig['env'] ?? ''),
            'is_dev' => $env !== 'prod',
            'root_path' => $this->rootPath,
            'budgets' => $devtoolsConfig['budgets'],
            'show_secrets' => (bool) $devtoolsConfig['show_secrets'],
            'collect_db' => (bool) $devtoolsConfig['collect_db'],
            'collect_request' => (bool) $devtoolsConfig['collect_request'],
            'collect_logs' => (bool) $devtoolsConfig['collect_logs'],
            'store_sql' => $storeSql,
            'request_id' => $requestId,
        ]);
        RequestScope::set('devtools.context', $devtoolsContext);
        RequestContext::setStartTime($devtoolsContext->getStartedAt());

        $dbProfileCollector = new DbProfileCollector();
        RequestScope::set('db.profile', $dbProfileCollector);

        $database->enableDevTools($devtoolsContext, $devtoolsConfig);
        $database->enableDbProfiling($dbProfileCollector);

        $settingsProvider = new SettingsProvider(
            $database,
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

        $logger = $this->createLogger($appConfig, $requestId);

        $this->validateTheme($publicTheme, $appConfig, $devtoolsContext, $logger);

        $sessionFactory = new SessionFactory($securityConfig['session'] ?? [], $logger, $this->rootPath);
        $session = $sessionFactory->create();
        $authService = $this->createAuthService($database, $logger, $session);
        $authorization = $this->createAuthorizationService($database);

        $templateRawMode = (string) ($securityConfig['template']['raw_mode']
            ?? $securityConfig['template_raw_mode']
            ?? 'escape');
        $themeRegistry = $this->resolveThemeRegistry();
        $templateResolver = $this->resolveTemplateResolver();
        $defaultTheme = $themeRegistry?->default();
        $resolverForEngine = null;
        if ($templateResolver instanceof TemplateResolver && $defaultTheme instanceof ThemeInterface) {
            $resolverForEngine = $templateResolver->withFallback(
                static fn (string $template, ThemeInterface $theme): string => $themeManager->resolvePath($template)
            );
        }
        $templateEngine = new TemplateEngine(
            $themeManager,
            new TemplateCompiler(),
            $this->rootPath . '/storage/cache/templates',
            $appDebug,
            $templateRawMode,
            $resolverForEngine,
            $defaultTheme
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
            $database,
            $assets,
            $templateRawMode,
            $themeRegistry,
            $templateResolver
        );
        return new KernelRequestContext(
            $appConfig,
            $securityConfig,
            $devtoolsConfig,
            $perfConfig,
            $bootEnabled,
            $appDebug,
            $env,
            $requestId,
            $perfEnabled,
            $request,
            $devtoolsContext,
            $router,
            $view,
            $translator,
            $localeResolver,
            $locale,
            $resolution,
            $sessionFactory,
            $session,
            $authService,
            $authorization,
            $logger
        );
    }

    /**
     * @param array<string, mixed> $appConfig
     */
    private function createLogger(array $appConfig, string $requestId): LoggerInterface
    {
        $loggerFactory = new LoggerFactory($this->rootPath);
        $logger = $loggerFactory->create($appConfig);
        if ($logger instanceof \Monolog\Logger) {
            $logger->pushProcessor(static function (\Monolog\LogRecord $record) use ($requestId): \Monolog\LogRecord {
                return $record->with(extra: array_merge($record->extra, [
                    'request_id' => $requestId,
                ]));
            });
        }

        return $logger;
    }

    private function resolveThemeRegistry(): ?ThemeRegistry
    {
        try {
            $themeRegistry = $this->container->get(ThemeRegistry::class);
            if ($themeRegistry instanceof ThemeRegistry) {
                return $themeRegistry;
            }
        } catch (\Throwable) {
            return null;
        }

        return null;
    }

    private function resolveTemplateResolver(): ?TemplateResolver
    {
        try {
            $templateResolver = $this->container->get(TemplateResolver::class);
            if ($templateResolver instanceof TemplateResolver) {
                return $templateResolver;
            }
        } catch (\Throwable) {
            return null;
        }

        return null;
    }

    /**
     * @param array<string, mixed> $appConfig
     */
    private function validateTheme(
        string $publicTheme,
        array $appConfig,
        DevToolsContext $devtoolsContext,
        LoggerInterface $logger
    ): void {
        $themeValidator = new ThemeValidator($this->rootPath . '/themes');
        $themeValidation = $themeValidator->validateTheme($publicTheme);
        if ($themeValidation->hasViolations()) {
            foreach ($themeValidation->getViolations() as $violation) {
                $code = (string) $violation['code'];
                $file = (string) $violation['file'];
                $message = (string) $violation['message'];
                $logger->warning('Theme validation warning', [
                    'theme' => $publicTheme,
                    'code' => $code,
                    'file' => $file,
                    'message' => $message,
                ]);
                if ((bool) ($appConfig['debug'] ?? false)) {
                    $devtoolsContext->addWarning('theme_validation', $message);
                }
            }
        }
        if ($themeValidation->hasWarnings()) {
            foreach ($themeValidation->getWarnings() as $warning) {
                $code = (string) $warning['code'];
                $file = (string) $warning['file'];
                $message = (string) $warning['message'];
                $logger->notice('Theme compat warning', [
                    'theme' => $publicTheme,
                    'code' => $code,
                    'file' => $file,
                    'message' => $message,
                ]);
                if ((bool) ($appConfig['debug'] ?? false)) {
                    $devtoolsContext->addWarning('theme_compat', $message);
                }
            }
        }
    }

    private function createAuthService(
        DatabaseManager $database,
        LoggerInterface $logger,
        SessionInterface $session
    ): AuthInterface {
        try {
            $usersRepository = new UsersRepository($database->pdo());
            return new AuthService($usersRepository, $session, $logger);
        } catch (\Throwable) {
            return new NullAuthService();
        }
    }

    private function createAuthorizationService(DatabaseManager $database): AuthorizationService
    {
        try {
            if (!$database->healthCheck()) {
                return new AuthorizationService(null);
            }

            return new AuthorizationService(new RbacRepository($database->pdo()));
        } catch (\Throwable) {
            return new AuthorizationService(null);
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
