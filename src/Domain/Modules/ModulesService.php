<?php

declare(strict_types=1);

namespace Laas\Domain\Modules;

use Laas\Database\DatabaseManager;
use Laas\Database\Repositories\ModulesRepository;
use Laas\Domain\Modules\Dto\ModuleSummary;
use Laas\Modules\ModuleCatalog;
use RuntimeException;
use Throwable;

class ModulesService implements ModulesServiceInterface
{
    public function __construct(
        private DatabaseManager $db,
        private array $config,
        private string $rootPath
    ) {
    }

    /** @return ModuleSummary[] */
    public function listModules(): array
    {
        $rows = $this->catalog()->listAll();
        $out = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $out[] = ModuleSummary::fromArray($row);
        }
        return $out;
    }

    public function findModuleById(string $moduleId): ?ModuleSummary
    {
        $moduleId = strtolower(trim($moduleId));
        if ($moduleId === '') {
            return null;
        }

        foreach ($this->listModules() as $module) {
            if ($module->moduleId() === $moduleId) {
                return $module;
            }
        }

        return null;
    }

    public function findModuleByName(string $name): ?ModuleSummary
    {
        $name = trim($name);
        if ($name === '') {
            return null;
        }

        foreach ($this->listModules() as $module) {
            if ($module->name() === $name) {
                return $module;
            }
        }

        return null;
    }

    /**
     * @return array{enabled: bool, row: array<string, mixed>}
     * @mutation
     */
    public function toggleModule(string $name): array
    {
        $name = trim($name);
        if ($name === '') {
            throw new RuntimeException('Module name is required.');
        }

        $repo = $this->modulesRepository();
        $current = $repo->all();
        $enabled = !empty($current[$name]['enabled']);
        if ($enabled) {
            $repo->disable($name);
        } else {
            $repo->enable($name);
        }

        $row = $current[$name] ?? ['enabled' => !$enabled, 'version' => null];
        $row['enabled'] = !$enabled;

        return [
            'enabled' => !$enabled,
            'row' => $row,
        ];
    }

    private function catalog(): ModuleCatalog
    {
        return new ModuleCatalog(
            $this->rootPath,
            $this->db,
            $this->config['modules'] ?? null,
            $this->config['modules_nav'] ?? null
        );
    }

    private function modulesRepository(): ModulesRepository
    {
        if (!$this->db->healthCheck()) {
            throw new RuntimeException('Database unavailable.');
        }

        try {
            return new ModulesRepository($this->db->pdo());
        } catch (Throwable $e) {
            throw new RuntimeException('Database unavailable.', 0, $e);
        }
    }
}
