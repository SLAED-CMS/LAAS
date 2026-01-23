<?php

declare(strict_types=1);

namespace Laas\Core;

use Laas\Auth\AuthInterface;
use Laas\Auth\AuthorizationService;
use Laas\DevTools\DevToolsContext;
use Laas\Http\Request;
use Laas\I18n\LocaleResolver;
use Laas\I18n\Translator;
use Laas\Routing\Router;
use Laas\Session\SessionFactory;
use Laas\Session\SessionInterface;
use Laas\View\View;
use Psr\Log\LoggerInterface;

final class KernelRequestContext
{
    /**
     * @param array<string, mixed> $appConfig
     * @param array<string, mixed> $securityConfig
     * @param array<string, mixed> $devtoolsConfig
     * @param array<string, mixed> $perfConfig
     * @param array<string, mixed> $localeResolution
     */
    public function __construct(
        public readonly array $appConfig,
        public readonly array $securityConfig,
        public readonly array $devtoolsConfig,
        public readonly array $perfConfig,
        public readonly bool $bootEnabled,
        public readonly bool $appDebug,
        public readonly string $env,
        public readonly string $requestId,
        public readonly bool $perfEnabled,
        public readonly Request $request,
        public readonly DevToolsContext $devtoolsContext,
        public readonly Router $router,
        public readonly View $view,
        public readonly Translator $translator,
        public readonly LocaleResolver $localeResolver,
        public readonly string $locale,
        public readonly array $localeResolution,
        public readonly SessionFactory $sessionFactory,
        public readonly SessionInterface $session,
        public readonly AuthInterface $authService,
        public readonly AuthorizationService $authorization,
        public readonly LoggerInterface $logger
    ) {
    }
}
