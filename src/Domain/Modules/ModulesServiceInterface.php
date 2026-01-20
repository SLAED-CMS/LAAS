<?php
declare(strict_types=1);

namespace Laas\Domain\Modules;

interface ModulesServiceInterface
{
    /** @return array<int, array<string, mixed>> */
    public function listModules(): array;

    /** @return array<string, mixed>|null */
    public function findModuleById(string $moduleId): ?array;

    /** @return array<string, mixed>|null */
    public function findModuleByName(string $name): ?array;

    /**
     * @return array{enabled: bool, row: array<string, mixed>}
     * @mutation
     */
    public function toggleModule(string $name): array;
}
