<?php

declare(strict_types=1);

namespace Laas\Perf;

final class PerfBudgetResult
{
    /** @var array<int, array{metric: string, value: float|int, threshold: float|int, level: string}> */
    private array $violations = [];
    private bool $hard = false;

    /**
     * @return array<int, array{metric: string, value: float|int, threshold: float|int, level: string}>
     */
    public function getViolations(): array
    {
        return $this->violations;
    }

    public function addViolation(string $metric, float|int $value, float|int $threshold, string $level): void
    {
        $this->violations[] = [
            'metric' => $metric,
            'value' => $value,
            'threshold' => $threshold,
            'level' => $level,
        ];
        if ($level === 'hard') {
            $this->hard = true;
        }
    }

    public function hasViolations(): bool
    {
        return $this->violations !== [];
    }

    public function isHard(): bool
    {
        return $this->hard;
    }
}
