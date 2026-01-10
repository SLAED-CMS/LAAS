<?php
declare(strict_types=1);

namespace Laas\View;

use Laas\Http\Request;
use Laas\Http\Response;
use Laas\Auth\AuthInterface;
use Laas\Security\Csrf;
use Laas\I18n\Translator;
use Laas\Settings\SettingsProvider;
use Laas\Database\DatabaseManager;
use Laas\Modules\Menu\Repository\MenusRepository;
use Laas\Modules\Menu\Repository\MenuItemsRepository;
use Laas\Support\Cache\CacheFactory;
use Laas\Support\Cache\CacheKey;
use Laas\Support\RequestScope;
use Laas\DevTools\DevToolsContext;
use Laas\View\AssetManager;
use Laas\View\ViewModelInterface;
use Laas\View\Template\TemplateEngine;
use Laas\View\Template\TemplateCompiler;
use Laas\View\Theme\ThemeManager;
use Laas\Ui\PresentationLeakDetector;

final class View
{
    private ?Request $request = null;

    public function __construct(
        private ThemeManager $themeManager,
        private TemplateEngine $engine,
        private Translator $translator,
        private string $locale,
        private array $appConfig,
        private AssetManager $assetManager,
        private AuthInterface $authService,
        private SettingsProvider $settingsProvider,
        private string $cachePath,
        private ?DatabaseManager $db = null,
        private array $assets = []
    ) {
        $this->defaultTheme = $themeManager->getThemeName();
        $this->themesRoot = $themeManager->getThemesRoot();
        $this->cachePath = $cachePath;
        $this->debug = (bool) ($appConfig['debug'] ?? false);
        $this->enforceUiTokens = (bool) ($appConfig['enforce_ui_tokens'] ?? false);
        $this->env = (string) ($appConfig['env'] ?? '');
    }

    private string $defaultTheme;
    private string $themesRoot;
    private bool $debug;
    private bool $enforceUiTokens;
    private string $env;

    public function setRequest(Request $request): void
    {
        $this->request = $request;
    }

    public function render(
        string $template,
        array|ViewModelInterface $data = [],
        int $status = 200,
        array $headers = [],
        array $options = []
    ): Response
    {
        $data = $this->normalizeViewModels($data);
        $ctx = array_merge($this->globalContext(), $data);
        $devtoolsEnabled = $this->resolveDevtoolsEnabled();
        if (!isset($ctx['devtools']) || !is_array($ctx['devtools'])) {
            $ctx['devtools'] = ['enabled' => $devtoolsEnabled];
        } elseif (!array_key_exists('enabled', $ctx['devtools'])) {
            $ctx['devtools']['enabled'] = $devtoolsEnabled;
        }
        $warnings = PresentationLeakDetector::detectArray($data);
        if ($warnings !== []) {
            $this->handlePresentationWarnings($warnings);
        }
        $renderOptions = [
            'render_partial' => $this->request?->isHtmx() ?? false,
        ];
        $renderOptions = array_merge($renderOptions, $options);

        $theme = $renderOptions['theme'] ?? $this->themeManager->getPublicTheme();
        $engine = $this->resolveEngine($theme);
        $ctx['__menu'] = function (string $name) use ($engine): string {
            if ($this->db === null || !$this->db->healthCheck()) {
                return '';
            }

            try {
                $menusRepo = new MenusRepository($this->db);
                $itemsRepo = new MenuItemsRepository($this->db);
            } catch (\Throwable) {
                return '';
            }

            $rootPath = dirname($this->cachePath, 3);
            $cache = CacheFactory::create($rootPath);
            $cacheConfig = CacheFactory::config($rootPath);
            $ttlMenus = (int) ($cacheConfig['ttl_menus'] ?? $cacheConfig['ttl_default'] ?? 60);
            $cacheKey = CacheKey::menu($name, $this->locale);
            $cached = $cache->get($cacheKey);

            if (is_array($cached) && isset($cached['menu'], $cached['items'])) {
                $menu = $cached['menu'];
                $items = $cached['items'];
            } else {
                $menu = $menusRepo->findMenuByName($name);
                if ($menu === null) {
                    return '';
                }

                $items = $itemsRepo->listItems((int) $menu['id'], true);
                $cache->set($cacheKey, [
                    'menu' => $menu,
                    'items' => $items,
                ], $ttlMenus);
            }

            $currentPath = $this->request?->getPath() ?? '/';
            $currentPath = '/' . ltrim($currentPath, '/');
            $currentPath = $currentPath === '/' ? '/' : rtrim($currentPath, '/');

            $items = array_map(function (array $item) use ($currentPath): array {
                $url = (string) ($item['url'] ?? '');
                $isExternal = (bool) ($item['is_external'] ?? false);

                $matchUrl = $url;
                if ($matchUrl !== '' && !str_starts_with($matchUrl, 'http://') && !str_starts_with($matchUrl, 'https://')) {
                    $matchUrl = '/' . ltrim($matchUrl, '/');
                } else {
                    $matchUrl = '';
                }

                $active = false;
                if ($matchUrl !== '') {
                    if ($matchUrl === '/') {
                        $active = $currentPath === '/';
                    } else {
                        $active = $currentPath === $matchUrl || str_starts_with($currentPath, $matchUrl . '/');
                    }
                }

                $item['is_external'] = $isExternal;
                $item['active'] = $active;

                return $item;
            }, $items);

            return $engine->render('partials/menu.html', [
                'menu' => $menu,
                'items' => $items,
            ], [
                'render_partial' => true,
            ]);
        };
        $html = $engine->render($template, $ctx, $renderOptions);

        $headers = array_merge([
            'Content-Type' => 'text/html; charset=utf-8',
        ], $headers);

        return new Response($html, $status, $headers);
    }

    public function renderPartial(string $template, array|ViewModelInterface $data = [], array $options = []): string
    {
        $options = array_merge(['render_partial' => true], $options);
        $response = $this->render($template, $data, 200, [], $options);
        return $response->getBody();
    }

    public function translate(string $key, array $params = []): string
    {
        return $this->translator->trans($key, $params, $this->locale);
    }

    public function getTranslator(): Translator
    {
        return $this->translator;
    }

    private function globalContext(): array
    {
        $csrfToken = '';
        $session = $this->request?->session();
        if ($session !== null && $session->isStarted()) {
            $csrfToken = (new Csrf($session))->getToken();
        }

        $devtoolsEnabled = $this->resolveDevtoolsEnabled();

        return [
            'csrf_token' => $csrfToken,
            '__translator' => $this->translator,
            '__assets' => $this->assetManager,
            'assets' => $this->assets,
            'locale' => $this->locale,
            'is_auth' => $this->authService->check(),
            'auth_user' => $this->authService->user(),
            'site_name' => (string) $this->settingsProvider->get('site_name', $this->appConfig['name'] ?? 'LAAS'),
            'app' => [
                'name' => $this->appConfig['name'] ?? 'LAAS',
                'version' => (string) ($this->appConfig['version'] ?? ''),
                'env' => (string) ($this->appConfig['env'] ?? ''),
                'debug' => (bool) ($this->appConfig['debug'] ?? false),
            ],
            'user' => $this->buildUserContext(),
            'request' => [
                'path' => $this->request?->getPath() ?? '/',
                'is_htmx' => $this->request?->isHtmx() ?? false,
            ],
            'devtools' => [
                'enabled' => $devtoolsEnabled,
                'flags' => [
                    'enabled' => $devtoolsEnabled,
                ],
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

    /**
     * @param array<int, array{key: string, path: string, code: string}> $warnings
     */
    private function handlePresentationWarnings(array $warnings): void
    {
        $context = RequestScope::get('devtools.context');
        foreach ($warnings as $warning) {
            $path = (string) ($warning['path'] ?? '');
            $code = (string) ($warning['code'] ?? 'presentation_leak');
            $message = 'Presentation key in view data: ' . $path;
            error_log('[ui-tokens] ' . $message);
            if ($this->debug && $context instanceof DevToolsContext) {
                $context->addWarning($code, $message);
            }
        }

        if ($this->shouldThrowOnPresentationLeak()) {
            throw new \DomainException('Presentation keys detected in view data');
        }
    }

    private function shouldThrowOnPresentationLeak(): bool
    {
        if (!$this->enforceUiTokens) {
            return false;
        }
        if (!$this->debug) {
            return false;
        }
        return strtolower($this->env) !== 'prod';
    }

    private function buildUserContext(): array
    {
        $user = $this->authService->user();
        if (!is_array($user)) {
            return [
                'id' => null,
                'username' => null,
                'roles' => [],
            ];
        }

        $roles = $user['roles'] ?? [];
        if (!is_array($roles)) {
            $roles = [];
        }

        return [
            'id' => $user['id'] ?? null,
            'username' => $user['username'] ?? null,
            'roles' => $roles,
        ];
    }

    private function normalizeViewModels(array|ViewModelInterface $data): array
    {
        if ($data instanceof ViewModelInterface) {
            return $data->toArray();
        }

        $out = [];
        foreach ($data as $key => $value) {
        if ($value instanceof ViewModelInterface) {
            $out[$key] = $value->toArray();
            continue;
        }
            if (is_array($value)) {
                $out[$key] = $this->normalizeViewModels($value);
                continue;
            }
            $out[$key] = $value;
        }

        return $out;
    }

    private function resolveDevtoolsEnabled(): bool
    {
        $context = RequestScope::get('devtools.context');
        if ($context instanceof DevToolsContext) {
            return (bool) $context->getFlag('enabled', false);
        }
        return false;
    }
}
