<?php

declare(strict_types=1);

namespace Laas\Database;

final class DbProfileCollector
{
    private int $totalCount = 0;
    private float $totalMs = 0.0;
    /** @var array<string, array{count: int, total_ms: float}> */
    private array $fingerprints = [];
    /** @var array<int, array{fingerprint: string, duration_ms: float}> */
    private array $topSlow = [];

    public function addQuery(string $sql, int $paramsCount, float $durationMs): void
    {
        $fingerprint = DbSqlFingerprint::fingerprint($sql, $paramsCount);

        $this->totalCount++;
        $this->totalMs += $durationMs;

        if (!isset($this->fingerprints[$fingerprint])) {
            $this->fingerprints[$fingerprint] = [
                'count' => 0,
                'total_ms' => 0.0,
            ];
        }
        $this->fingerprints[$fingerprint]['count']++;
        $this->fingerprints[$fingerprint]['total_ms'] += $durationMs;

        $this->recordTopSlow($fingerprint, $durationMs);
    }

    /** @return array{total_count: int, total_ms: float, unique_count: int, duplicates_count: int, top_slow: array<int, array{duration_ms: float, fingerprint: string}>} */
    public function toArray(): array
    {
        $topSlow = $this->topSlow;
        usort($topSlow, static function (array $a, array $b): int {
            return ($b['duration_ms'] ?? 0) <=> ($a['duration_ms'] ?? 0);
        });
        $topSlow = array_slice($topSlow, 0, 5);
        $topSlow = array_map(static function (array $row): array {
            return [
                'duration_ms' => round((float) ($row['duration_ms'] ?? 0), 2),
                'fingerprint' => (string) ($row['fingerprint'] ?? ''),
            ];
        }, $topSlow);

        return [
            'total_count' => $this->totalCount,
            'total_ms' => round($this->totalMs, 2),
            'unique_count' => count($this->fingerprints),
            'duplicates_count' => $this->duplicatesCount(),
            'top_slow' => $topSlow,
        ];
    }

    private function duplicatesCount(): int
    {
        $count = 0;
        foreach ($this->fingerprints as $row) {
            if (($row['count'] ?? 0) > 1) {
                $count++;
            }
        }
        return $count;
    }

    private function recordTopSlow(string $fingerprint, float $durationMs): void
    {
        $this->topSlow[] = [
            'fingerprint' => $fingerprint,
            'duration_ms' => $durationMs,
        ];

        if (count($this->topSlow) <= 8) {
            return;
        }

        usort($this->topSlow, static function (array $a, array $b): int {
            return ($b['duration_ms'] ?? 0) <=> ($a['duration_ms'] ?? 0);
        });
        $this->topSlow = array_slice($this->topSlow, 0, 5);
    }
}
