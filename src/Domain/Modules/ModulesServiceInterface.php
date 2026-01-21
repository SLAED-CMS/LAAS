<?php
declare(strict_types=1);

namespace Laas\Domain\Modules;

use Laas\Domain\Modules\Dto\ModuleSummary;

interface ModulesServiceInterface
{
    /** @return ModuleSummary[] */
    public function listModules(): array;

    public function findModuleById(string $moduleId): ?ModuleSummary;

    public function findModuleByName(string $name): ?ModuleSummary;

    /**
     * @return array{enabled: bool, row: array<string, mixed>}
     * @mutation
     */
    public function toggleModule(string $name): array;
}
