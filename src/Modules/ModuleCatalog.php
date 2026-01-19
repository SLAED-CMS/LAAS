<?php
declare(strict_types=1);

namespace Laas\Modules;

use Laas\Database\DatabaseManager;
use Laas\Database\Repositories\ModulesRepository;
use Laas\Support\RequestScope;

final class ModuleCatalog
{
    private const CACHE_KEY = 'modules.catalog';

    public function __construct(
        private string $rootPath,
        private ?DatabaseManager $db = null,
        private ?array $configModules = null
    ) {
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listAll(): array
    {
        if (RequestScope::has(self::CACHE_KEY)) {
            $cached = RequestScope::get(self::CACHE_KEY);
            if (is_array($cached)) {
                return $cached;
            }
        }

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
            $modules[] = [
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
            ];
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
                $modules[] = $virtual;
            }
        }

        usort($modules, static fn(array $a, array $b): int => strcmp((string) $a['name'], (string) $b['name']));

        RequestScope::set(self::CACHE_KEY, $modules);

        return $modules;
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
            'actions_nav' => array_slice($actions, 0, 2),
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
}
