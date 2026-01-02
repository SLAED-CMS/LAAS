<?php
declare(strict_types=1);

namespace Laas\Modules;

use Laas\Database\DatabaseManager;
use Laas\Database\Repositories\ModulesRepository;
use Laas\Routing\Router;
use Laas\View\View;
use PDOException;
use RuntimeException;

final class ModuleManager
{
    public function __construct(
        private array $moduleClasses,
        private View $view,
        private ?DatabaseManager $db = null
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
        $modulesDir = dirname(__DIR__, 2) . '/modules';
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

        if ($this->db === null) {
            return $configEnabled;
        }

        try {
            if (!$this->db->healthCheck()) {
                return $configEnabled;
            }

            $repo = new ModulesRepository($this->db->pdo());
            $repo->sync($this->discover(), $configEnabled);
            $all = $repo->all();

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

    private function moduleNameFromClass(string $class): string
    {
        $parts = explode('\\', trim($class, '\\'));
        return $parts[2] ?? '';
    }

    private function instantiateModule(string $class): object
    {
        $ctor = (new \ReflectionClass($class))->getConstructor();
        if ($ctor !== null && $ctor->getNumberOfParameters() >= 2 && $this->db !== null) {
            return new $class($this->view, $this->db);
        }

        return new $class($this->view);
    }
}
