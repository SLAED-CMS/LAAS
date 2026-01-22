<?php

declare(strict_types=1);

namespace Laas\Perf;

use Laas\DevTools\DevToolsContext;
use Laas\Http\RequestContext;
use Laas\Support\RequestScope;

final class PerfSnapshot
{
    public function __construct(
        private float $totalMs,
        private float $memoryMb,
        private int $sqlCount,
        private int $sqlUnique,
        private int $sqlDup,
        private float $sqlMs
    ) {
    }

    public static function fromRequest(): self
    {
        $context = RequestScope::get('devtools.context');
        if ($context instanceof DevToolsContext) {
            $context->finalize();
            return new self(
                $context->getDurationMs(),
                $context->getMemoryPeakMb(),
                $context->getDbCount(),
                $context->getDbUniqueCount(),
                $context->getDbDuplicateCount(),
                $context->getDbTotalMs()
            );
        }

        $metrics = RequestContext::metrics();
        return new self(
            (float) ($metrics['total_ms'] ?? 0),
            (float) ($metrics['memory_mb'] ?? 0),
            (int) ($metrics['sql_count'] ?? 0),
            (int) ($metrics['sql_unique'] ?? 0),
            (int) ($metrics['sql_dup'] ?? 0),
            (float) ($metrics['sql_ms'] ?? 0)
        );
    }

    /**
     * @return array{total_ms: float, memory_mb: float, sql_count: int, sql_unique: int, sql_dup: int, sql_ms: float}
     */
    public function toArray(): array
    {
        return [
            'total_ms' => $this->totalMs,
            'memory_mb' => $this->memoryMb,
            'sql_count' => $this->sqlCount,
            'sql_unique' => $this->sqlUnique,
            'sql_dup' => $this->sqlDup,
            'sql_ms' => $this->sqlMs,
        ];
    }

    public function totalMs(): float
    {
        return $this->totalMs;
    }

    public function memoryMb(): float
    {
        return $this->memoryMb;
    }

    public function sqlCount(): int
    {
        return $this->sqlCount;
    }

    public function sqlUnique(): int
    {
        return $this->sqlUnique;
    }

    public function sqlDup(): int
    {
        return $this->sqlDup;
    }

    public function sqlMs(): float
    {
        return $this->sqlMs;
    }
}
