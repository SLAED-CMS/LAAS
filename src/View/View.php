<?php
declare(strict_types=1);

namespace Laas\View;

use Laas\Http\Request;
use Laas\Http\Response;
use Laas\Auth\AuthInterface;
use Laas\Security\Csrf;
use Laas\I18n\Translator;
use Laas\Settings\SettingsProvider;
use Laas\View\Template\TemplateEngine;
use Laas\View\Template\TemplateCompiler;
use Laas\View\Theme\ThemeManager;

final class View
{
    private ?Request $request = null;

    public function __construct(
        private ThemeManager $themeManager,
        private TemplateEngine $engine,
        private Translator $translator,
        private string $locale,
        private array $appConfig,
        private AuthInterface $authService,
        private SettingsProvider $settingsProvider,
        private string $cachePath
    ) {
        $this->defaultTheme = $themeManager->getThemeName();
        $this->themesRoot = $themeManager->getThemesRoot();
        $this->cachePath = $cachePath;
        $this->debug = (bool) ($appConfig['debug'] ?? false);
    }

    private string $defaultTheme;
    private string $themesRoot;
    private bool $debug;

    public function setRequest(Request $request): void
    {
        $this->request = $request;
    }

    public function render(
        string $template,
        array $data = [],
        int $status = 200,
        array $headers = [],
        array $options = []
    ): Response
    {
        $ctx = array_merge($this->globalContext(), $data);
        $renderOptions = [
            'render_partial' => $this->request?->isHtmx() ?? false,
        ];
        $renderOptions = array_merge($renderOptions, $options);

        $theme = $renderOptions['theme'] ?? $this->themeManager->getPublicTheme();
        $engine = $this->resolveEngine($theme);
        $html = $engine->render($template, $ctx, $renderOptions);

        $headers = array_merge([
            'Content-Type' => 'text/html; charset=utf-8',
        ], $headers);

        return new Response($html, $status, $headers);
    }

    private function globalContext(): array
    {
        $csrfToken = '';
        if (session_status() === PHP_SESSION_ACTIVE) {
            $csrfToken = (new Csrf())->getToken();
        }

        return [
            'csrf_token' => $csrfToken,
            '__translator' => $this->translator,
            'locale' => $this->locale,
            'is_auth' => $this->authService->check(),
            'auth_user' => $this->authService->user(),
            'site_name' => (string) $this->settingsProvider->get('site_name', $this->appConfig['name'] ?? 'LAAS'),
            'app' => [
                'name' => $this->appConfig['name'] ?? 'LAAS',
                'debug' => (bool) ($this->appConfig['debug'] ?? false),
            ],
            'request' => [
                'path' => $this->request?->getPath() ?? '/',
                'is_htmx' => $this->request?->isHtmx() ?? false,
            ],
        ];
    }

    private function resolveEngine(string $theme): TemplateEngine
    {
        if ($theme === $this->defaultTheme) {
            return $this->engine;
        }

        $themeManager = new ThemeManager($this->themesRoot, $theme, $this->settingsProvider);
        return new TemplateEngine(
            $themeManager,
            new TemplateCompiler(),
            $this->cachePath,
            $this->debug
        );
    }
}
