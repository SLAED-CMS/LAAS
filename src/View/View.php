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
        private AssetManager $assetManager,
        private AuthInterface $authService,
        private SettingsProvider $settingsProvider,
        private string $cachePath,
        private ?DatabaseManager $db = null
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
        $devtoolsEnabled = $this->resolveDevtoolsEnabled();
        if (!isset($ctx['devtools']) || !is_array($ctx['devtools'])) {
            $ctx['devtools'] = ['enabled' => $devtoolsEnabled];
        } elseif (!array_key_exists('enabled', $ctx['devtools'])) {
            $ctx['devtools']['enabled'] = $devtoolsEnabled;
        }
        if ($this->debug) {
            $this->assertNoClassTokens($data);
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

    public function renderPartial(string $template, array $data = [], array $options = []): string
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

        return [
            'csrf_token' => $csrfToken,
            '__translator' => $this->translator,
            '__assets' => $this->assetManager,
            'assets' => $this->assetManager,
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
                'enabled' => $this->resolveDevtoolsEnabled(),
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

    private function assertNoClassTokens(array $data): void
    {
        $stack = [$data];

        while ($stack !== []) {
            $current = array_pop($stack);
            if (!is_array($current)) {
                continue;
            }

            foreach ($current as $key => $value) {
                if (is_string($key) && str_ends_with($key, '_class')) {
                    throw new \RuntimeException('View data contains forbidden key: ' . $key);
                }
                if (is_array($value)) {
                    $stack[] = $value;
                }
            }
        }
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

    private function resolveDevtoolsEnabled(): bool
    {
        $context = RequestScope::get('devtools.context');
        if ($context instanceof DevToolsContext) {
            return (bool) $context->getFlag('enabled', false);
        }
        return false;
    }
}
