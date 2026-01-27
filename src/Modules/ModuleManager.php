<?php

declare(strict_types=1);

namespace Laas\Modules;

use Laas\Core\Container\Container;
use Laas\Database\DatabaseManager;
use Laas\Database\Repositories\ModulesRepository;
use Laas\DevTools\ModulesDiscoveryStats;
use Laas\Routing\Router;
use Laas\Support\RequestScope;
use Laas\View\View;
use PDOException;
use RuntimeException;

final class ModuleManager
{
    public function __construct(
        private array $moduleClasses,
        private View $view,
        private ?DatabaseManager $db = null,
        private ?Container $container = null
    ) {
    }

    public function register(Router $router): void
    {
        $enabled = $this->resolveEnabledModules();

        foreach ($this->moduleClasses as $class) {
            if (!class_exists($class)) {
                continue;
            }

            $moduleName = $this->moduleNameFromClass($class);
            if ($moduleName !== '' && !in_array($moduleName, $enabled, true)) {
                continue;
            }

            $module = $this->instantiateModule($class);
            if ($module instanceof ModuleInterface) {
                $module->registerRoutes($router);
            }
        }
    }

    /** @return array<string, array{path: string, version: string|null, type: string}> */
    public function discover(): array
    {
        $t0 = microtime(true);
        $modulesDir = dirname(__DIR__, 2) . '/modules';
        if (!is_dir($modulesDir)) {
            ModulesDiscoveryStats::record('discover', (microtime(true) - $t0) * 1000, 0);
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
            $metaPath = $path . '/module.json';
            if (is_file($metaPath)) {
                $raw = (string) file_get_contents($metaPath);
                $data = json_decode($raw, true);
                if (is_array($data)) {
                    $name = is_string($data['name'] ?? null) ? $data['name'] : $name;
                    $version = is_string($data['version'] ?? null) ? $data['version'] : null;
                    $type = is_string($data['type'] ?? null) ? $data['type'] : $type;
                }
            }

            $discovered[$name] = [
                'path' => $path,
                'version' => $version,
                'type' => $type,
            ];
        }

        ModulesDiscoveryStats::record('discover', (microtime(true) - $t0) * 1000, count($discovered));
        return $discovered;
    }

    /** @return array<int, string> */
    private function resolveEnabledModules(): array
    {
        $configEnabled = [];
        foreach ($this->moduleClasses as $class) {
            $name = $this->moduleNameFromClass($class);
            if ($name !== '') {
                $configEnabled[] = $name;
            }
        }

        $snapshot = $this->resolveSnapshot();
        if ($this->shouldUseSnapshot()) {
            if ($snapshot !== null) {
                $cached = $snapshot->load();
                if ($cached !== []) {
                    return $cached;
                }
            }

            return $configEnabled;
        }

        if ($this->db === null) {
            return $configEnabled;
        }

        try {
            if (!$this->db->healthCheck()) {
                return $configEnabled;
            }

            $repo = new ModulesRepository($this->db->pdo());
            $syncStart = microtime(true);
            $repo->sync($this->discover(), $configEnabled);
            $all = $repo->all();
            ModulesDiscoveryStats::record('sync', (microtime(true) - $syncStart) * 1000, count($all));
            if ($snapshot !== null) {
                $snapshot->rebuild();
            }

            $enabled = [];
            foreach ($all as $name => $row) {
                if (!empty($row['enabled'])) {
                    $enabled[] = $name;
                }
            }

            return $enabled !== [] ? $enabled : $configEnabled;
        } catch (PDOException) {
            return $configEnabled;
        } catch (RuntimeException) {
            return $configEnabled;
        }
    }

    private function shouldUseSnapshot(): bool
    {
        $request = RequestScope::getRequest();
        if (PHP_SAPI === 'cli' && $request === null) {
            return false;
        }
        if ($request === null) {
            return false;
        }

        $path = $request->getPath();
        if (str_starts_with($path, '/admin')) {
            return false;
        }

        return true;
    }

    private function resolveSnapshot(): ?ModulesSnapshot
    {
        if ($this->container === null) {
            return null;
        }

        try {
            $snapshot = $this->container->get(ModulesSnapshot::class);
        } catch (\Throwable) {
            return null;
        }

        return $snapshot instanceof ModulesSnapshot ? $snapshot : null;
    }

    private function moduleNameFromClass(string $class): string
    {
        $parts = explode('\\', trim($class, '\\'));
        return $parts[2] ?? '';
    }

    private function instantiateModule(string $class): object
    {
        $ctor = (new \ReflectionClass($class))->getConstructor();
        if ($ctor !== null && $this->db !== null) {
            $paramCount = $ctor->getNumberOfParameters();
            if ($paramCount >= 3 && $this->container !== null) {
                return new $class($this->view, $this->db, $this->container);
            }
            if ($paramCount >= 2) {
                return new $class($this->view, $this->db);
            }
        }

        return new $class($this->view);
    }
}
