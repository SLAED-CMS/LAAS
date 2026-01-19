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

            $modules[] = [
                'name' => $name,
                'key' => $name,
                'type' => $type,
                'enabled' => $enabled,
                'admin_url' => '/admin/modules',
                'notes' => is_string($meta['description'] ?? null) ? (string) $meta['description'] : '',
                'version' => $meta['version'] ?? null,
                'protected' => $type !== 'feature',
                'source' => $source,
                'type_is_internal' => $type === 'internal',
                'type_is_admin' => $type === 'admin',
                'type_is_api' => $type === 'api',
            ];
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
}
