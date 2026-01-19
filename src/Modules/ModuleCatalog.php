<?php
declare(strict_types=1);

namespace Laas\Modules;

use Laas\Database\DatabaseManager;
use Laas\Database\Repositories\ModulesRepository;
use Laas\Support\RequestCache;
use Laas\Support\RequestScope;

final class ModuleCatalog
{
    private const CACHE_KEY = 'modules.catalog';

    public function __construct(
        private string $rootPath,
        private ?DatabaseManager $db = null,
        private ?array $configModules = null,
        private ?array $navConfig = null
    ) {
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listAll(): array
    {
        $cacheKey = $this->cacheKey();
        $cached = RequestCache::remember($cacheKey, function (): array {
            $discovered = $this->discoverModules();
            $configEnabled = $this->loadConfigEnabled();
            $dbModules = null;
            $dbAvailable = $this->db !== null && $this->db->healthCheck();
            if ($dbAvailable) {
                $repo = new ModulesRepository($this->db->pdo());
                $dbModules = $repo->all();
            }

            if ($discovered === []) {
                foreach ($configEnabled as $name) {
                    $discovered[$name] = [
                        'path' => '',
                        'version' => null,
                        'type' => 'feature',
                        'description' => null,
                    ];
                }
            }

            $modules = [];
            foreach ($discovered as $name => $meta) {
                $enabled = false;
                $source = 'CONFIG';
                if ($dbAvailable && is_array($dbModules) && array_key_exists($name, $dbModules)) {
                    $enabled = (bool) ($dbModules[$name]['enabled'] ?? false);
                    $source = 'DB';
                } elseif (in_array($name, $configEnabled, true)) {
                    $enabled = true;
                }

                $type = $this->normalizeType((string) ($meta['type'] ?? 'feature'));
                if ($type !== 'feature' && $enabled === false && !$dbAvailable && !in_array($name, $configEnabled, true)) {
                    $enabled = true;
                    $source = 'INTERNAL';
                }

                $moduleId = $this->normalizeModuleId($name);
                $adminUrl = $this->resolveAdminUrl($name);
                $detailsAnchor = '#module-' . $moduleId;
                $detailsUrl = '/admin/modules/details?module=' . $moduleId;
                $icon = $this->resolveIcon($name, $type, $adminUrl);
                $actions = $this->resolveActions($name, $adminUrl, $detailsAnchor);
                $installedAt = null;
                $updatedAt = null;
                if ($dbAvailable && is_array($dbModules) && array_key_exists($name, $dbModules)) {
                    $installedAt = is_string($dbModules[$name]['installed_at'] ?? null) ? (string) $dbModules[$name]['installed_at'] : null;
                    $updatedAt = is_string($dbModules[$name]['updated_at'] ?? null) ? (string) $dbModules[$name]['updated_at'] : null;
                }
                $modules[] = $this->applyNavMeta([
                    'name' => $name,
                    'key' => $name,
                    'module_id' => $moduleId,
                    'type' => $type,
                    'enabled' => $enabled,
                    'admin_url' => $adminUrl,
                    'details_anchor' => $detailsAnchor,
                    'details_url' => $detailsUrl,
                    'notes' => is_string($meta['description'] ?? null) ? (string) $meta['description'] : '',
                    'virtual' => false,
                    'icon' => $icon,
                    'actions' => $actions,
                    'actions_nav' => array_slice($actions, 0, 2),
                    'version' => $meta['version'] ?? null,
                    'installed_at' => $installedAt,
                    'updated_at' => $updatedAt,
                    'protected' => $type !== 'feature',
                    'source' => $source,
                    'type_is_internal' => $type === 'internal',
                    'type_is_admin' => $type === 'admin',
                    'type_is_api' => $type === 'api',
                ]);
            }

            $virtuals = $this->virtualModules();
            if ($virtuals !== []) {
                $existing = [];
                foreach ($modules as $module) {
                    if (is_array($module) && isset($module['name'])) {
                        $existing[(string) $module['name']] = true;
                    }
                }
                foreach ($virtuals as $virtual) {
                    if (!is_array($virtual)) {
                        continue;
                    }
                    $name = (string) ($virtual['name'] ?? '');
                    if ($name === '' || isset($existing[$name])) {
                        continue;
                    }
                    $modules[] = $this->applyNavMeta($virtual);
                }
            }

            usort($modules, static fn(array $a, array $b): int => strcmp((string) $a['name'], (string) $b['name']));

            return $modules;
        });

        return is_array($cached) ? $cached : [];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listNav(): array
    {
        $modules = $this->listAll();
        usort($modules, fn(array $a, array $b): int => $this->compareNavModules($a, $b));
        return $modules;
    }

    /**
     * @return array<int, array{key: string, title: string, items: array<int, array<string, mixed>>}>
     */
    public function listNavSections(): array
    {
        $modules = $this->listNav();
        return $this->buildNavSections($modules);
    }

    private function normalizeType(string $type): string
    {
        $type = strtolower($type);
        return match ($type) {
            'internal', 'admin', 'api', 'feature' => $type,
            default => 'feature',
        };
    }

    /**
     * @return array<string, array{path: string, version: string|null, type: string, description: string|null}>
     */
    private function discoverModules(): array
    {
        $modulesDir = $this->rootPath . '/modules';
        $modulesDir = realpath($modulesDir) ?: $modulesDir;
        if (!is_dir($modulesDir)) {
            return [];
        }

        $items = scandir($modulesDir) ?: [];
        $discovered = [];
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $modulesDir . '/' . $item;
            if (!is_dir($path)) {
                continue;
            }

            $name = $item;
            $version = null;
            $type = 'feature';
            $description = null;
            $metaPath = $path . '/module.json';
            if (is_file($metaPath)) {
                $raw = (string) file_get_contents($metaPath);
                $data = json_decode($raw, true);
                if (is_array($data)) {
                    $name = is_string($data['name'] ?? null) ? $data['name'] : $name;
                    $version = is_string($data['version'] ?? null) ? $data['version'] : null;
                    $type = is_string($data['type'] ?? null) ? $data['type'] : $type;
                    $description = is_string($data['description'] ?? null) ? $data['description'] : null;
                }
            }

            $discovered[$name] = [
                'path' => $path,
                'version' => $version,
                'type' => $type,
                'description' => $description,
            ];
        }

        if (!isset($discovered['Audit'])) {
            $adminVersion = $discovered['Admin']['version'] ?? null;
            $discovered['Audit'] = [
                'path' => '',
                'version' => $adminVersion,
                'type' => 'internal',
                'description' => null,
            ];
        }

        return $discovered;
    }

    /**
     * @return string[]
     */
    private function loadConfigEnabled(): array
    {
        $config = $this->configModules;
        if ($config === null) {
            $configPath = $this->rootPath . '/config/modules.php';
            $configPath = realpath($configPath) ?: $configPath;
            if (is_file($configPath)) {
                $config = require $configPath;
            }
        }

        if (!is_array($config)) {
            return [];
        }

        if (isset($config['enabled']) && is_array($config['enabled'])) {
            return array_values(array_filter($config['enabled'], 'is_string'));
        }

        $names = [];
        foreach ($config as $class) {
            if (!is_string($class)) {
                continue;
            }
            $parts = explode('\\', trim($class, '\\'));
            $name = $parts[2] ?? '';
            if ($name !== '') {
                $names[] = $name;
            }
        }

        return $names;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function virtualModules(): array
    {
        $moduleId = 'ai';
        $detailsAnchor = '#module-' . $moduleId;
        $detailsUrl = '/admin/modules/details?module=' . $moduleId;
        $actions = $this->resolveActions('AI', '/admin/ai', $detailsAnchor);

        return [[
            'name' => 'AI',
            'key' => 'AI',
            'module_id' => $moduleId,
            'slug' => 'ai',
            'type' => 'internal',
            'enabled' => true,
            'admin_url' => '/admin/ai',
            'details_anchor' => $detailsAnchor,
            'details_url' => $detailsUrl,
            'notes' => 'AI Assistant (Admin UI + API, read-only; apply via CLI)',
            'virtual' => true,
            'icon' => 'robot',
            'actions' => $actions,
            'version' => null,
            'installed_at' => null,
            'updated_at' => null,
            'protected' => true,
            'source' => 'VIRTUAL',
            'type_is_internal' => true,
            'type_is_admin' => false,
            'type_is_api' => false,
        ]];
    }

    private function resolveAdminUrl(string $name): ?string
    {
        return match (strtolower($name)) {
            'ai' => '/admin/ai',
            'admin' => '/admin',
            'pages' => '/admin/pages',
            'media' => '/admin/media',
            'menu', 'menus' => '/admin/menus',
            'users' => '/admin/users',
            'changelog' => '/admin/changelog',
            'audit' => '/admin/audit',
            default => null,
        };
    }

    private function resolveIcon(string $name, string $type, ?string $adminUrl): string
    {
        $key = $this->normalizeKey($name);
        return match ($key) {
            'ai' => 'robot',
            'admin' => 'speedometer2',
            'api' => 'plug',
            'pages' => 'file-earmark-text',
            'media' => 'images',
            'menu', 'menus' => 'list',
            'users' => 'people',
            'settings' => 'sliders',
            'ops' => 'activity',
            'securityreports' => 'shield-check',
            'changelog' => 'clock-history',
            'modules' => 'grid-3x3-gap',
            'audit' => 'clipboard-check',
            default => $this->fallbackIcon($type, $adminUrl),
        };
    }

    private function fallbackIcon(string $type, ?string $adminUrl): string
    {
        if ($type === 'internal' && $adminUrl === null) {
            return 'lock';
        }

        return match ($type) {
            'admin' => 'speedometer2',
            'api' => 'plug',
            'internal' => 'lock',
            default => 'box-seam',
        };
    }

    /**
     * @return array<int, array{label: string, url: string, style: string, icon: string}>
     */
    private function resolveActions(string $name, ?string $adminUrl, string $detailsAnchor): array
    {
        $key = $this->normalizeKey($name);
        $actions = [];

        if ($key === 'pages') {
            if ($adminUrl !== null) {
                $actions[] = $this->buildAction('Open', $adminUrl, 'secondary', 'box-arrow-up-right');
            }
            $actions[] = $this->buildAction('New', '/admin/pages/new', 'primary', 'plus-lg');
        } elseif ($key === 'modules') {
            $actions[] = $this->buildAction('Open', '/admin/modules', 'secondary', 'box-arrow-up-right');
        } else {
            if ($adminUrl !== null) {
                $actions[] = $this->buildAction('Open', $adminUrl, 'secondary', 'box-arrow-up-right');
            }
        }

        $actions[] = $this->buildAction('Details', $detailsAnchor, 'outline-secondary', 'info-circle');

        return $actions;
    }

    /**
     * @return array{label: string, url: string, style: string, icon: string}
     */
    private function buildAction(string $label, string $url, string $style, string $icon): array
    {
        return [
            'label' => $label,
            'url' => $url,
            'style' => $style,
            'icon' => $icon,
        ];
    }

    private function normalizeKey(string $name): string
    {
        $name = strtolower($name);
        $normalized = preg_replace('/[^a-z0-9]+/', '', $name);
        return $normalized ?? $name;
    }

    private function normalizeModuleId(string $name): string
    {
        $name = strtolower($name);
        $normalized = preg_replace('/[^a-z0-9]+/', '-', $name);
        $normalized = trim($normalized ?? $name, '-');
        return $normalized !== '' ? $normalized : $name;
    }

    private function cacheKey(): string
    {
        $modulesSignature = $this->configModules !== null ? md5(serialize($this->configModules)) : 'default';
        $navSignature = $this->navConfig !== null ? md5(serialize($this->navConfig)) : 'default';
        return self::CACHE_KEY . '.' . $modulesSignature . '.' . $navSignature;
    }

    /**
     * @param array<string, mixed> $module
     * @return array<string, mixed>
     */
    private function applyNavMeta(array $module): array
    {
        $navConfig = $this->loadNavConfig();
        $name = (string) ($module['name'] ?? '');
        $moduleConfig = $this->resolveNavConfig($navConfig, $name);

        $group = $this->normalizeNavGroup((string) ($moduleConfig['group'] ?? $this->inferNavGroup($module)));
        $pinned = (bool) ($moduleConfig['pinned'] ?? $this->isPinnedFromConfig($navConfig, $name));
        $navPriority = (int) ($moduleConfig['nav_priority'] ?? 100);
        $navLabel = (string) ($moduleConfig['nav_label'] ?? $name);
        $navBadge = (string) ($moduleConfig['nav_badge'] ?? $this->resolveNavBadge($module));
        $navBadgeTone = (string) ($moduleConfig['nav_badge_tone'] ?? $this->resolveNavBadgeTone($module, $navBadge));
        $navActions = $this->resolveNavActions($module, $moduleConfig, $navConfig);

        $search = trim(strtolower(
            $this->normalizeKey($name)
            . ' '
            . $name
            . ' '
            . $navLabel
            . ' '
            . (string) ($module['notes'] ?? '')
        ));

        $module['group'] = $group;
        $module['pinned'] = $pinned;
        $module['nav_priority'] = $navPriority;
        $module['nav_label'] = $navLabel;
        $module['nav_badge'] = $navBadge;
        $module['nav_badge_tone'] = $navBadgeTone;
        $module['actions_nav'] = $navActions;
        $module['nav_search'] = $search;

        return $module;
    }

    private function compareNavModules(array $a, array $b): int
    {
        $groupA = $this->normalizeNavGroup((string) ($a['group'] ?? 'system'));
        $groupB = $this->normalizeNavGroup((string) ($b['group'] ?? 'system'));
        $groupRank = $this->navGroupRank($groupA) <=> $this->navGroupRank($groupB);
        if ($groupRank !== 0) {
            return $groupRank;
        }

        $pinnedA = !empty($a['pinned']);
        $pinnedB = !empty($b['pinned']);
        if ($pinnedA !== $pinnedB) {
            return $pinnedA ? -1 : 1;
        }

        $priorityA = (int) ($a['nav_priority'] ?? 100);
        $priorityB = (int) ($b['nav_priority'] ?? 100);
        if ($priorityA !== $priorityB) {
            return $priorityA <=> $priorityB;
        }

        return strcmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? ''));
    }

    /**
     * @param array<int, array<string, mixed>> $modules
     * @return array<int, array{key: string, title: string, items: array<int, array<string, mixed>>}>
     */
    private function buildNavSections(array $modules): array
    {
        $sections = [
            'pinned' => ['key' => 'pinned', 'title' => 'Pinned', 'items' => []],
            'core' => ['key' => 'core', 'title' => 'Core', 'items' => []],
            'content' => ['key' => 'content', 'title' => 'Content', 'items' => []],
            'system' => ['key' => 'system', 'title' => 'System', 'items' => []],
            'dev' => ['key' => 'dev', 'title' => 'Dev', 'items' => []],
            'demo' => ['key' => 'demo', 'title' => 'Demo', 'items' => []],
        ];

        foreach ($modules as $module) {
            if (!is_array($module)) {
                continue;
            }
            if (!empty($module['pinned'])) {
                $sections['pinned']['items'][] = $module;
                continue;
            }

            $group = $this->normalizeNavGroup((string) ($module['group'] ?? 'system'));
            $sectionKey = $this->resolveSectionKey($group);
            if (!isset($sections[$sectionKey])) {
                $sections[$sectionKey] = ['key' => $sectionKey, 'title' => ucfirst($sectionKey), 'items' => []];
            }
            $sections[$sectionKey]['items'][] = $module;
        }

        $ordered = [];
        foreach (['pinned', 'core', 'content', 'system', 'dev', 'demo'] as $key) {
            if ($key === 'demo' && empty($sections[$key]['items'])) {
                continue;
            }
            $ordered[] = $sections[$key];
        }

        return $ordered;
    }

    private function resolveSectionKey(string $group): string
    {
        return match ($group) {
            'api', 'internal' => 'system',
            default => $group,
        };
    }

    private function loadNavConfig(): array
    {
        $config = $this->navConfig;
        if ($config === null) {
            $configPath = $this->rootPath . '/config/modules_nav.php';
            $configPath = realpath($configPath) ?: $configPath;
            if (is_file($configPath)) {
                $config = require $configPath;
            }
        }

        return is_array($config) ? $config : [];
    }

    private function resolveNavConfig(array $navConfig, string $name): array
    {
        if (!isset($navConfig['modules']) || !is_array($navConfig['modules'])) {
            return [];
        }

        $key = $this->normalizeKey($name);
        $modulesConfig = $navConfig['modules'];
        $entry = $modulesConfig[$name] ?? $modulesConfig[$key] ?? [];
        return is_array($entry) ? $entry : [];
    }

    private function isPinnedFromConfig(array $navConfig, string $name): bool
    {
        if (!isset($navConfig['pinned']) || !is_array($navConfig['pinned'])) {
            return false;
        }

        $key = $this->normalizeKey($name);
        foreach ($navConfig['pinned'] as $item) {
            if (!is_string($item)) {
                continue;
            }
            if ($item === $name || $this->normalizeKey($item) === $key) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $module
     */
    private function inferNavGroup(array $module): string
    {
        $name = (string) ($module['name'] ?? '');
        $key = $this->normalizeKey($name);

        $map = [
            'admin' => 'core',
            'users' => 'core',
            'pages' => 'content',
            'menu' => 'content',
            'menus' => 'content',
            'media' => 'content',
            'system' => 'system',
            'ops' => 'system',
            'securityreports' => 'system',
            'audit' => 'system',
            'api' => 'api',
            'devtools' => 'dev',
            'changelog' => 'dev',
            'demo' => 'demo',
            'demoblog' => 'demo',
            'demoenv' => 'demo',
            'ai' => 'demo',
        ];

        if (isset($map[$key])) {
            return $map[$key];
        }

        $type = (string) ($module['type'] ?? 'feature');
        return match ($type) {
            'admin' => 'core',
            'api' => 'api',
            'internal' => 'system',
            default => 'content',
        };
    }

    private function normalizeNavGroup(string $group): string
    {
        $group = strtolower(trim($group));
        return match ($group) {
            'core', 'content', 'system', 'dev', 'api', 'internal', 'demo' => $group,
            default => 'system',
        };
    }

    private function navGroupRank(string $group): int
    {
        return match ($group) {
            'core' => 10,
            'content' => 20,
            'system' => 30,
            'internal' => 31,
            'api' => 32,
            'dev' => 40,
            'demo' => 50,
            default => 99,
        };
    }

    /**
     * @param array<string, mixed> $module
     */
    private function resolveNavBadge(array $module): string
    {
        if (!empty($module['virtual'])) {
            return 'VIRTUAL';
        }

        $type = strtoupper((string) ($module['type'] ?? ''));
        if ($type !== '' && $type !== 'FEATURE') {
            return $type;
        }

        return !empty($module['enabled']) ? 'ON' : 'OFF';
    }

    /**
     * @param array<string, mixed> $module
     */
    private function resolveNavBadgeTone(array $module, string $badge): string
    {
        $badge = strtoupper($badge);
        return match ($badge) {
            'ON' => 'success',
            'OFF' => 'secondary',
            'ADMIN' => 'dark',
            'API' => 'info',
            'INTERNAL' => 'secondary',
            'DEMO' => 'warning',
            'VIRTUAL' => 'light',
            default => !empty($module['enabled']) ? 'success' : 'secondary',
        };
    }

    /**
     * @param array<string, mixed> $module
     * @param array<string, mixed> $moduleConfig
     * @param array<string, mixed> $navConfig
     * @return array<int, array{label: string, url: string, style: string, icon: string}>
     */
    private function resolveNavActions(array $module, array $moduleConfig, array $navConfig): array
    {
        $actions = $module['actions'] ?? [];
        if (!is_array($actions)) {
            return [];
        }

        $labels = ['Open', 'New', 'Details'];
        if (isset($navConfig['actions_nav_default']) && is_array($navConfig['actions_nav_default'])) {
            $labels = array_values(array_filter($navConfig['actions_nav_default'], 'is_string'));
        }
        if (isset($moduleConfig['actions_nav']) && is_array($moduleConfig['actions_nav'])) {
            $labels = array_values(array_filter($moduleConfig['actions_nav'], 'is_string'));
        }

        $detailsAnchor = (string) ($module['details_anchor'] ?? '');
        $detailsUrl = $detailsAnchor !== '' ? '/admin/modules' . $detailsAnchor : '';

        $allowed = [];
        foreach ($actions as $action) {
            if (!is_array($action)) {
                continue;
            }
            $label = (string) ($action['label'] ?? '');
            if ($label === '' || !in_array($label, $labels, true)) {
                continue;
            }

            $url = (string) ($action['url'] ?? '');
            if ($label === 'Details' && $detailsUrl !== '') {
                $url = $detailsUrl;
            }
            if ($url === '' || !$this->isNavActionAllowed($navConfig, $url)) {
                continue;
            }

            $allowed[] = [
                'label' => $label,
                'url' => $url,
                'style' => (string) ($action['style'] ?? 'secondary'),
                'icon' => (string) ($action['icon'] ?? 'box-arrow-up-right'),
            ];
        }

        return $allowed;
    }

    private function isNavActionAllowed(array $navConfig, string $url): bool
    {
        $allowlist = $navConfig['actions_allowlist'] ?? [];
        if (!is_array($allowlist) || $allowlist === []) {
            $allowlist = ['/admin*', '#'];
        }

        foreach ($allowlist as $allowed) {
            if (!is_string($allowed) || $allowed === '') {
                continue;
            }
            if (str_ends_with($allowed, '*')) {
                $prefix = substr($allowed, 0, -1);
                if ($prefix !== '' && str_starts_with($url, $prefix)) {
                    return true;
                }
                continue;
            }
            if ($url === $allowed) {
                return true;
            }
        }

        return false;
    }
}
