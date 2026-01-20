<?php
declare(strict_types=1);

namespace Laas\Modules\System\Controller;

use Laas\Core\Container\Container;
use Laas\Domain\Audit\AuditLogServiceInterface;
use Laas\Domain\Menus\MenusServiceInterface;
use Laas\Domain\Media\MediaServiceInterface;
use Laas\Domain\Pages\PagesServiceInterface;
use Laas\Domain\Rbac\RbacServiceInterface;
use Laas\Domain\Settings\SettingsServiceInterface;
use Laas\Domain\Users\UsersServiceInterface;
use Laas\DevTools\DevToolsContext;
use Laas\Http\Request;
use Laas\Http\Response;
use Laas\Modules\Changelog\Service\ChangelogService;
use Laas\Modules\Changelog\Support\ChangelogCache;
use Laas\Modules\Changelog\Support\ChangelogSettings;
use Laas\Modules\Media\Service\StorageService;
use Laas\Support\Search\Highlighter;
use Laas\Support\Search\SearchNormalizer;
use Laas\Support\Search\SearchQuery;
use Laas\View\View;
use RuntimeException;
use Throwable;

final class HomeController
{
    private ?PagesServiceInterface $pagesService = null;
    private ?MediaServiceInterface $mediaService = null;
    private ?MenusServiceInterface $menusService = null;
    private ?SettingsServiceInterface $settingsService = null;
    private ?UsersServiceInterface $usersService = null;
    private ?RbacServiceInterface $rbacService = null;
    private ?AuditLogServiceInterface $auditService = null;

    public function __construct(
        private View $view,
        private ?Container $container = null
    )
    {
    }

    public function index(Request $request): Response
    {
        $appConfig = $this->appConfig();
        $blocks = $this->resolveBlocks($appConfig);
        $blockFlags = array_fill_keys($blocks, true);

        if (!$this->isShowcaseEnabled($appConfig)) {
            return $this->view->render('home/index.html', [
                'home_enabled' => false,
                'blocks' => $blockFlags,
            ]);
        }

        $query = SearchNormalizer::normalize((string) ($request->query('q') ?? ''));
        if ($request->isHtmx() && $request->query('pages_q') !== null) {
            $pagesData = $this->pagesData((string) ($request->query('pages_q') ?? ''), 5);
            return $this->view->render('home/_pages_list.html', ['pages' => $pagesData], $pagesData['pages_status'], [], [
                'render_partial' => true,
            ]);
        }

        if ($request->isHtmx() && $request->query('q') !== null) {
            $searchData = $this->searchData($query, 5);
            return $this->view->render('home/_search_results.html', ['search' => $searchData], $searchData['status'], [], [
                'render_partial' => true,
            ]);
        }

        $data = [
            'home_enabled' => true,
            'blocks' => $blockFlags,
            'system' => $this->systemData($appConfig),
            'pages' => $this->pagesData('', 5),
            'media' => $this->mediaData(6),
            'menus' => $this->menuData($request),
            'search' => $this->searchData('', 5),
            'auth' => $this->authData($request),
            'audit' => $this->auditData($request, 5),
            'perf' => $this->perfData($appConfig),
            'features' => $this->featuresData(),
            'changelog' => $this->changelogData(),
        ];

        return $this->view->render('home/index.html', $data);
    }

    private function systemData(array $appConfig): array
    {
        $storage = $this->storageConfig();
        $cache = $this->cacheConfig();
        $media = $this->mediaConfig();
        $health = $this->healthStatus($storage);

        return [
            'version' => (string) ($appConfig['version'] ?? ''),
            'env' => (string) ($appConfig['env'] ?? ''),
            'read_only' => (bool) ($appConfig['read_only'] ?? false),
            'storage' => (string) ($storage['default'] ?? 'local'),
            'cache_enabled' => (bool) ($cache['enabled'] ?? true),
            'media_mode' => (string) ($media['public_mode'] ?? 'private'),
            'media_public' => (string) ($media['public_mode'] ?? 'private') === 'public',
            'media_signed' => (string) ($media['public_mode'] ?? 'private') === 'signed',
            'health_ok' => $health['ok'],
        ];
    }

    private function pagesData(string $query, int $limit): array
    {
        $query = SearchNormalizer::normalize($query);
        $errors = [];
        $pages = [];
        $status = 200;

        $service = $this->pagesService();
        if ($service === null) {
            return [
                'pages_query' => $query,
                'pages' => [],
                'pages_errors' => [],
                'pages_status' => 200,
            ];
        }

        if (SearchNormalizer::isTooShort($query)) {
            $errors[] = $this->view->translate('search.too_short');
            $status = 422;
        } elseif ($query !== '') {
            $search = new SearchQuery($query, $limit, 1, 'pages');
            try {
                $rows = $service->list([
                    'query' => $search->q,
                    'status' => 'published',
                    'limit' => $search->limit,
                    'offset' => $search->offset,
                ]);
            } catch (Throwable) {
                return [
                    'pages_query' => $query,
                    'pages' => [],
                    'pages_errors' => [],
                    'pages_status' => 503,
                ];
            }
            foreach ($rows as $row) {
                $title = (string) ($row['title'] ?? '');
                $content = (string) ($row['content'] ?? '');
                $pages[] = [
                    'title' => $title,
                    'slug' => (string) ($row['slug'] ?? ''),
                    'excerpt' => $this->excerpt($content),
                    'title_segments' => Highlighter::segments($title, $search->q),
                ];
            }
        } else {
            try {
                $rows = $service->list([
                    'status' => 'published',
                    'limit' => $limit,
                    'offset' => 0,
                ]);
            } catch (Throwable) {
                $rows = [];
            }
            foreach ($rows as $row) {
                $pages[] = [
                    'title' => (string) ($row['title'] ?? ''),
                    'slug' => (string) ($row['slug'] ?? ''),
                    'excerpt' => $this->excerpt((string) ($row['content'] ?? '')),
                    'title_segments' => null,
                ];
            }
        }

        return [
            'pages_query' => $query,
            'pages' => $pages,
            'pages_errors' => $errors,
            'pages_status' => $status,
        ];
    }

    private function mediaData(int $limit): array
    {
        $service = $this->mediaService();
        if ($service === null) {
            return [
                'items' => [],
            ];
        }

        $config = $this->mediaConfig();
        $mode = (string) ($config['public_mode'] ?? 'private');
        try {
            $rows = $service->list($limit, 0, '');
        } catch (Throwable) {
            $rows = [];
        }
        $items = [];
        foreach ($rows as $row) {
            $id = (int) ($row['id'] ?? 0);
            $mime = (string) ($row['mime_type'] ?? '');
            $isImage = str_starts_with($mime, 'image/');
            $isPublic = !empty($row['is_public']);
            $access = 'private';
            if ($mode === 'all') {
                $access = 'public';
            } elseif ($mode === 'signed' && $isPublic) {
                $access = 'signed';
            }
            $sizeBytes = (int) ($row['size_bytes'] ?? 0);

            $items[] = [
                'id' => $id,
                'name' => (string) ($row['original_name'] ?? ''),
                'mime' => $mime,
                'size' => $sizeBytes,
                'size_human' => $this->formatBytes($sizeBytes),
                'is_image' => $isImage,
                'icon' => $this->mediaIcon($mime),
                'thumb_url' => $id > 0 ? '/media/' . $id . '/thumb/sm' : '',
                'url' => $id > 0 ? '/media/' . $id . '/file' : '',
                'access' => $access,
                'access_public' => $access === 'public',
                'access_signed' => $access === 'signed',
            ];
        }

        return [
            'items' => $items,
        ];
    }

    private function menuData(Request $request): array
    {
        $service = $this->menusService();
        if ($service === null) {
            return [
                'menu' => null,
                'items' => [],
                'cache_badge' => $this->menuCacheBadge(),
            ];
        }

        $menu = $service->findByName('main');
        if ($menu === null) {
            return [
                'menu' => null,
                'items' => [],
                'cache_badge' => $this->menuCacheBadge(),
            ];
        }

        $items = $service->loadItems((int) $menu['id'], true);
        $currentPath = '/' . ltrim($request->getPath(), '/');
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

        return [
            'menu' => $menu,
            'items' => $items,
            'cache_badge' => $this->menuCacheBadge(),
        ];
    }

    private function searchData(string $query, int $limit): array
    {
        $query = SearchNormalizer::normalize($query);
        $errors = [];
        $results = [
            'pages' => [],
            'media' => [],
        ];
        $status = 200;

        if (SearchNormalizer::isTooShort($query)) {
            $errors[] = $this->view->translate('search.too_short');
            $status = 422;
        } elseif ($query !== '') {
            $search = new SearchQuery($query, $limit, 1, 'home');
            $pagesService = $this->pagesService();
            if ($pagesService !== null) {
                try {
                    $rows = $pagesService->list([
                        'query' => $search->q,
                        'status' => 'published',
                        'limit' => $search->limit,
                        'offset' => $search->offset,
                    ]);
                    foreach ($rows as $row) {
                        $title = (string) ($row['title'] ?? '');
                        $content = (string) ($row['content'] ?? '');
                        $results['pages'][] = [
                            'title_segments' => Highlighter::segments($title, $search->q),
                            'snippet_segments' => Highlighter::snippet($content, $search->q, 140),
                            'url' => '/' . (string) ($row['slug'] ?? ''),
                        ];
                    }
                } catch (Throwable) {
                    $results['pages'] = [];
                }
            }

            $mediaService = $this->mediaService();
            if ($mediaService !== null) {
                try {
                    $rows = $mediaService->search($search->q, $search->limit, $search->offset);
                    foreach ($rows as $row) {
                        $name = (string) ($row['original_name'] ?? '');
                        $mime = (string) ($row['mime_type'] ?? '');
                        $id = (int) ($row['id'] ?? 0);
                        $results['media'][] = [
                            'name_segments' => Highlighter::segments($name, $search->q),
                            'mime_segments' => Highlighter::segments($mime, $search->q),
                            'url' => $id > 0 ? '/media/' . $id . '/file' : '',
                        ];
                    }
                } catch (Throwable) {
                    $results['media'] = [];
                }
            }
        }

        return [
            'q' => $query,
            'results' => $results,
            'errors' => $errors,
            'status' => $status,
        ];
    }

    private function authData(Request $request): array
    {
        $user = $this->currentUser($request);
        if ($user === null) {
            return [
                'user' => null,
                'roles' => [],
                'permissions_count' => 0,
                'can_admin' => false,
                'can_media' => false,
            ];
        }

        $rbac = $this->rbacService();
        if ($rbac === null) {
            return [
                'user' => $user,
                'roles' => [],
                'permissions_count' => 0,
                'can_admin' => false,
                'can_media' => false,
            ];
        }

        $userId = (int) ($user['id'] ?? 0);
        $roles = [];
        $usersService = $this->usersService();
        if ($usersService !== null && $userId > 0) {
            try {
                $roles = $usersService->rolesForUser($userId);
            } catch (Throwable) {
                $roles = [];
            }
        }
        $permissionsCount = $this->permissionsCount($roles);

        return [
            'user' => $user,
            'roles' => $roles,
            'permissions_count' => $permissionsCount,
            'can_admin' => $userId > 0 ? $rbac->userHasPermission($userId, 'admin.access') : false,
            'can_media' => $userId > 0 ? $rbac->userHasPermission($userId, 'media.view') : false,
        ];
    }

    private function auditData(Request $request, int $limit): array
    {
        $user = $this->currentUser($request);
        $rbac = $this->rbacService();
        if ($user === null || $rbac === null) {
            return [
                'allowed' => false,
                'entries' => [],
            ];
        }

        $userId = (int) ($user['id'] ?? 0);
        if ($userId <= 0 || !$rbac->userHasPermission($userId, 'audit.view')) {
            return [
                'allowed' => false,
                'entries' => [],
            ];
        }

        $service = $this->auditService();
        if ($service === null) {
            return [
                'allowed' => true,
                'entries' => [],
            ];
        }

        try {
            $rows = $service->list($limit, 0);
        } catch (Throwable) {
            $rows = [];
        }

        return [
            'allowed' => true,
            'entries' => $this->auditEntries($rows),
        ];
    }

    private function perfData(array $appConfig): array
    {
        $debug = (bool) ($appConfig['debug'] ?? false);
        if (!$debug) {
            return [
                'enabled' => false,
            ];
        }

        $context = RequestScope::get('devtools.context');
        if (!$context instanceof DevToolsContext) {
            return [
                'enabled' => false,
            ];
        }

        $context->finalize();
        $data = $context->toArray();

        $perf = [
            'enabled' => true,
            'db_count' => (int) ($data['db']['count'] ?? 0),
            'db_total_ms' => (float) ($data['db']['total_ms'] ?? 0),
            'duration_ms' => (float) ($data['duration_ms'] ?? 0),
            'memory_mb' => (float) ($data['memory_mb'] ?? 0),
        ];
        $perf['db_count_status'] = $this->thresholdStatus($perf['db_count'], 20, 40);
        $perf['db_time_status'] = $this->thresholdStatus($perf['db_total_ms'], 50, 150);
        $perf['duration_status'] = $this->thresholdStatus($perf['duration_ms'], 150, 400);
        $perf['memory_status'] = $this->thresholdStatus($perf['memory_mb'], 64, 128);
        $perf['db_count_crit'] = $perf['db_count_status'] === 'crit';
        $perf['db_count_warn'] = $perf['db_count_status'] === 'warn';
        $perf['memory_crit'] = $perf['memory_status'] === 'crit';
        $perf['memory_warn'] = $perf['memory_status'] === 'warn';
        $perf['db_time_pct'] = $this->toPercent($perf['db_total_ms'], 200.0);
        $perf['duration_pct'] = $this->toPercent($perf['duration_ms'], 600.0);

        return $perf;
    }

    private function featuresData(): array
    {
        $storage = new StorageService($this->rootPath());
        $cache = $this->cacheConfig();
        $cacheEnabled = (bool) ($cache['enabled'] ?? true);
        $backupReady = is_dir($this->rootPath() . '/storage/backups');

        $features = [
            ['name' => 'Pages', 'status' => 'production-ready', 'variant' => 'success', 'hint' => 'Pages + search + slugs'],
            ['name' => 'Media', 'status' => $storage->isMisconfigured() ? 'enabled' : 'production-ready', 'variant' => $storage->isMisconfigured() ? 'secondary' : 'success', 'hint' => 'Uploads + thumbs + signed URLs'],
            ['name' => 'Search', 'status' => 'enabled', 'variant' => 'primary', 'hint' => 'Pages + media search'],
            ['name' => 'Changelog', 'status' => 'enabled', 'variant' => 'primary', 'hint' => 'GitHub/local git feed'],
            ['name' => 'RBAC', 'status' => 'production-ready', 'variant' => 'success', 'hint' => 'Roles + permissions'],
            ['name' => 'Audit', 'status' => 'production-ready', 'variant' => 'success', 'hint' => 'Action logging + filters'],
            ['name' => 'Cache', 'status' => $cacheEnabled ? 'configured' : 'disabled', 'variant' => $cacheEnabled ? 'warning' : 'secondary', 'hint' => 'Settings + menus'],
            ['name' => 'Backup', 'status' => $backupReady ? 'configured' : 'enabled', 'variant' => $backupReady ? 'warning' : 'secondary', 'hint' => 'Backup + restore CLI'],
            ['name' => 'CI', 'status' => 'production-ready', 'variant' => 'success', 'hint' => 'Lint + tests + release'],
        ];

        return array_map(static function (array $feature): array {
            $variant = (string) ($feature['variant'] ?? 'secondary');
            return array_merge($feature, [
                'variant_is_success' => $variant === 'success',
                'variant_is_warning' => $variant === 'warning',
                'variant_is_primary' => $variant === 'primary',
            ]);
        }, $features);
    }

    private function changelogData(): array
    {
        $settings = $this->changelogSettings();
        $enabled = (bool) ($settings['enabled'] ?? false);
        if (!$enabled) {
            return [
                'enabled' => false,
                'commits' => [],
                'source_is_git' => false,
                'source_is_github' => true,
                'error' => null,
            ];
        }

        $includeMerges = (bool) ($settings['show_merges'] ?? false);
        $perPage = (int) ($settings['per_page'] ?? 5);
        $settings['per_page'] = min(5, max(1, $perPage));

        $service = new ChangelogService($this->rootPath(), new ChangelogCache($this->rootPath()));
        try {
            $page = $service->fetchPage($settings, 1, $includeMerges, []);
            $data = $page->toArray();
            $commits = $data['commits'] ?? [];
        } catch (RuntimeException) {
            return [
                'enabled' => true,
                'commits' => [],
                'groups' => [],
                'source_is_git' => (string) ($settings['source_type'] ?? 'github') === 'git',
                'source_is_github' => (string) ($settings['source_type'] ?? 'github') === 'github',
                'error' => $this->view->translate('changelog.admin.test_fail'),
            ];
        }

        return [
            'enabled' => true,
            'commits' => $commits,
            'groups' => $this->groupCommitsByDate($commits),
            'source_is_git' => (string) ($settings['source_type'] ?? 'github') === 'git',
            'source_is_github' => (string) ($settings['source_type'] ?? 'github') === 'github',
            'error' => null,
        ];
    }

    private function changelogSettings(): array
    {
        return ChangelogSettings::load($this->rootPath(), $this->settingsService());
    }

    private function pagesService(): ?PagesServiceInterface
    {
        if ($this->pagesService !== null) {
            return $this->pagesService;
        }

        if ($this->container !== null) {
            try {
                $service = $this->container->get(PagesServiceInterface::class);
                if ($service instanceof PagesServiceInterface) {
                    $this->pagesService = $service;
                    return $this->pagesService;
                }
            } catch (Throwable) {
                return null;
            }
        }

        return null;
    }

    private function mediaService(): ?MediaServiceInterface
    {
        if ($this->mediaService !== null) {
            return $this->mediaService;
        }

        if ($this->container !== null) {
            try {
                $service = $this->container->get(MediaServiceInterface::class);
                if ($service instanceof MediaServiceInterface) {
                    $this->mediaService = $service;
                    return $this->mediaService;
                }
            } catch (Throwable) {
                return null;
            }
        }

        return null;
    }

    private function menusService(): ?MenusServiceInterface
    {
        if ($this->menusService !== null) {
            return $this->menusService;
        }

        if ($this->container !== null) {
            try {
                $service = $this->container->get(MenusServiceInterface::class);
                if ($service instanceof MenusServiceInterface) {
                    $this->menusService = $service;
                    return $this->menusService;
                }
            } catch (Throwable) {
                return null;
            }
        }

        return null;
    }

    private function settingsService(): ?SettingsServiceInterface
    {
        if ($this->settingsService !== null) {
            return $this->settingsService;
        }

        if ($this->container !== null) {
            try {
                $service = $this->container->get(SettingsServiceInterface::class);
                if ($service instanceof SettingsServiceInterface) {
                    $this->settingsService = $service;
                    return $this->settingsService;
                }
            } catch (Throwable) {
                return null;
            }
        }

        return null;
    }

    private function usersService(): ?UsersServiceInterface
    {
        if ($this->usersService !== null) {
            return $this->usersService;
        }

        if ($this->container !== null) {
            try {
                $service = $this->container->get(UsersServiceInterface::class);
                if ($service instanceof UsersServiceInterface) {
                    $this->usersService = $service;
                    return $this->usersService;
                }
            } catch (Throwable) {
                return null;
            }
        }

        return null;
    }

    private function rbacService(): ?RbacServiceInterface
    {
        if ($this->rbacService !== null) {
            return $this->rbacService;
        }

        if ($this->container !== null) {
            try {
                $service = $this->container->get(RbacServiceInterface::class);
                if ($service instanceof RbacServiceInterface) {
                    $this->rbacService = $service;
                    return $this->rbacService;
                }
            } catch (Throwable) {
                return null;
            }
        }

        return null;
    }

    private function auditService(): ?AuditLogServiceInterface
    {
        if ($this->auditService !== null) {
            return $this->auditService;
        }

        if ($this->container !== null) {
            try {
                $service = $this->container->get(AuditLogServiceInterface::class);
                if ($service instanceof AuditLogServiceInterface) {
                    $this->auditService = $service;
                    return $this->auditService;
                }
            } catch (Throwable) {
                return null;
            }
        }

        return null;
    }

    private function currentUser(Request $request): ?array
    {
        $session = $request->session();
        if (!$session->isStarted()) {
            return null;
        }

        $raw = $session->get('user_id');
        $userId = null;
        if (is_int($raw)) {
            $userId = $raw;
        } elseif (is_string($raw) && ctype_digit($raw)) {
            $userId = (int) $raw;
        }

        if ($userId === null || $userId <= 0) {
            return null;
        }

        $service = $this->usersService();
        if ($service === null) {
            return null;
        }

        try {
            return $service->find($userId);
        } catch (Throwable) {
            return null;
        }
    }

    /** @param array<int, string> $roles */
    private function permissionsCount(array $roles): int
    {
        $rbac = $this->rbacService();
        if ($rbac === null) {
            return 0;
        }

        $perms = [];
        foreach ($roles as $roleName) {
            if (!is_string($roleName) || $roleName === '') {
                continue;
            }
            $roleId = $rbac->findRoleIdByName($roleName);
            if ($roleId === null) {
                continue;
            }
            foreach ($rbac->listRolePermissions($roleId) as $perm) {
                if (is_string($perm) && $perm !== '') {
                    $perms[$perm] = true;
                }
            }
        }

        return count($perms);
    }

    private function excerpt(string $content, int $limit = 160): string
    {
        $text = strip_tags($content);
        $text = preg_replace('/\s+/', ' ', $text) ?? $text;
        $text = trim($text);
        if ($text === '') {
            return '';
        }

        $len = function_exists('mb_strlen') ? (int) mb_strlen($text) : strlen($text);
        if ($len <= $limit) {
            return $text;
        }

        $slice = function_exists('mb_substr') ? (string) mb_substr($text, 0, $limit) : substr($text, 0, $limit);
        return rtrim($slice) . '...';
    }

    private function rootPath(): string
    {
        return dirname(__DIR__, 3);
    }

    private function appConfig(): array
    {
        $path = $this->rootPath() . '/config/app.php';
        if (!is_file($path)) {
            return [];
        }
        $config = require $path;
        return is_array($config) ? $config : [];
    }

    private function mediaConfig(): array
    {
        $path = $this->rootPath() . '/config/media.php';
        if (!is_file($path)) {
            return [];
        }
        $config = require $path;
        return is_array($config) ? $config : [];
    }

    private function storageConfig(): array
    {
        $path = $this->rootPath() . '/config/storage.php';
        if (!is_file($path)) {
            return [];
        }
        $config = require $path;
        return is_array($config) ? $config : [];
    }

    private function cacheConfig(): array
    {
        $path = $this->rootPath() . '/config/cache.php';
        if (!is_file($path)) {
            return [];
        }
        $config = require $path;
        return is_array($config) ? $config : [];
    }

    private function healthStatus(array $storageConfig): array
    {
        $ok = true;
        if (!$this->dbHealthy()) {
            $ok = false;
        }

        $storage = new StorageService($this->rootPath());
        if ($storage->isMisconfigured()) {
            $ok = false;
        }

        return [
            'status' => $ok ? 'ok' : 'degraded',
            'ok' => $ok,
        ];
    }

    private function dbHealthy(): bool
    {
        $db = $this->container?->get('db');
        if (is_object($db) && method_exists($db, 'healthCheck')) {
            return (bool) $db->healthCheck();
        }

        return false;
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes <= 0) {
            return '0 B';
        }
        $units = ['B', 'KB', 'MB', 'GB'];
        $idx = (int) floor(log($bytes, 1024));
        $idx = min($idx, count($units) - 1);
        $value = $bytes / (1024 ** $idx);
        return number_format($value, $value >= 10 ? 0 : 1) . ' ' . $units[$idx];
    }

    private function mediaIcon(string $mime): string
    {
        if ($mime === 'application/pdf') {
            return 'bi-file-earmark-pdf';
        }
        if (str_starts_with($mime, 'image/')) {
            return 'bi-image';
        }
        return 'bi-file-earmark';
    }

    private function menuCacheBadge(): bool
    {
        $appConfig = $this->appConfig();
        $debug = (bool) ($appConfig['debug'] ?? false);
        $cache = $this->cacheConfig();
        return $debug && (bool) ($cache['enabled'] ?? true);
    }

    /** @return array<int, array<string, mixed>> */
    private function auditEntries(array $rows): array
    {
        $entries = [];
        foreach ($rows as $row) {
            $action = (string) ($row['action'] ?? '');
            $entries[] = array_merge($row, [
                'action_is_primary' => str_starts_with($action, 'media.'),
                'action_is_warning' => str_starts_with($action, 'settings.'),
                'action_is_auth' => str_starts_with($action, 'auth.'),
                'relative' => $this->relativeTime((string) ($row['created_at'] ?? '')),
            ]);
        }
        return $entries;
    }

    private function relativeTime(string $timestamp): string
    {
        $time = strtotime($timestamp);
        if ($time === false) {
            return '';
        }
        $diff = time() - $time;
        if ($diff < 60) {
            return 'just now';
        }
        if ($diff < 3600) {
            $min = (int) floor($diff / 60);
            return $min . ' min ago';
        }
        if ($diff < 86400) {
            $hours = (int) floor($diff / 3600);
            return $hours . ' h ago';
        }
        $days = (int) floor($diff / 86400);
        return $days . ' d ago';
    }

    private function thresholdStatus(float|int $value, float $warn, float $crit): string
    {
        if ($value >= $crit) {
            return 'crit';
        }
        if ($value >= $warn) {
            return 'warn';
        }
        return 'ok';
    }

    private function toPercent(float $value, float $max): int
    {
        if ($max <= 0.0) {
            return 0;
        }
        $pct = (int) round(($value / $max) * 100);
        return max(0, min(100, $pct));
    }

    private function isShowcaseEnabled(array $appConfig): bool
    {
        return (bool) ($appConfig['home_showcase_enabled'] ?? true);
    }

    /** @return array<int, string> */
    private function resolveBlocks(array $appConfig): array
    {
        $all = [
            'system',
            'pages',
            'media',
            'menus',
            'search',
            'auth',
            'audit',
            'perf',
            'changelog',
            'features',
        ];

        $blocks = $appConfig['home_showcase_blocks'] ?? [];
        if (!is_array($blocks) || $blocks === []) {
            return $all;
        }

        $blocks = array_values(array_filter($blocks, static fn($value): bool => is_string($value) && $value !== ''));
        $blocks = array_values(array_intersect($all, $blocks));
        return $blocks !== [] ? $blocks : $all;
    }

    /** @param array<int, array<string, mixed>> $commits */
    private function groupCommitsByDate(array $commits): array
    {
        $grouped = [];
        foreach ($commits as $commit) {
            $commit = $this->formatCommit($commit);
            $raw = (string) ($commit['committed_at'] ?? '');
            $date = $this->commitDate($raw);
            if (!isset($grouped[$date])) {
                $grouped[$date] = [];
            }
            $grouped[$date][] = $commit;
        }

        $out = [];
        foreach ($grouped as $date => $items) {
            $out[] = [
                'date' => $date,
                'items' => $items,
            ];
        }
        return $out;
    }

    private function commitDate(string $raw): string
    {
        if ($raw === '') {
            return 'Unknown';
        }
        $date = substr($raw, 0, 10);
        if (preg_match('/^\\d{4}-\\d{2}-\\d{2}$/', $date)) {
            return $date;
        }
        $parsed = strtotime($raw);
        if ($parsed !== false) {
            return date('Y-m-d', $parsed);
        }
        return 'Unknown';
    }

    /** @param array<string, mixed> $commit */
    private function formatCommit(array $commit): array
    {
        $title = (string) ($commit['title'] ?? '');
        $commit['title_is_release'] = $this->isReleaseTitle($title);
        $commit['title_clean'] = $this->cleanReleaseTitle($title);
        $commit['body_blocks'] = $this->bodyBlocks((string) ($commit['body'] ?? ''));
        return $commit;
    }

    private function isReleaseTitle(string $title): bool
    {
        $trimmed = ltrim($title);
        if ($trimmed === '') {
            return false;
        }
        if (str_starts_with($trimmed, '#')) {
            return true;
        }
        return (bool) preg_match('/^v\\d+(\\.\\d+){1,2}/i', $trimmed);
    }

    private function cleanReleaseTitle(string $title): string
    {
        $trimmed = ltrim($title);
        if ($trimmed === '') {
            return '';
        }
        if (str_starts_with($trimmed, '#')) {
            return trim(ltrim($trimmed, '#'));
        }
        return $trimmed;
    }

    /** @return array<int, array<string, mixed>> */
    private function bodyBlocks(string $body): array
    {
        $lines = preg_split("/\\r?\\n/", $body) ?: [];
        $blocks = [];
        $currentList = null;

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                $currentList = null;
                continue;
            }

            $isList = (bool) preg_match('/^([-*+]|\\d+\\.)\\s+/', $line);
            if ($isList) {
                $item = preg_replace('/^([-*+]|\\d+\\.)\\s+/', '', $line) ?? $line;
                if ($currentList === null) {
            $blocks[] = [
                'is_list' => true,
                'items' => [$item],
                'text' => '',
            ];
                    $currentList = count($blocks) - 1;
                } else {
                    $blocks[$currentList]['items'][] = $item;
                }
                continue;
            }

            $blocks[] = [
                'is_list' => false,
                'items' => [],
                'text' => $line,
            ];
            $currentList = null;
        }

        return $blocks;
    }
}
