<?php
declare(strict_types=1);

namespace Laas\DevTools;

final class DevToolsContext
{
    private string $requestId;
    private float $startedAt;
    private float $durationMs = 0.0;
    private float $dbTotalMs = 0.0;
    private int $dbCount = 0;
    private int $memoryPeak = 0;
    private array $dbQueries = [];
    private array $request = [];
    private array $user = [];
    private array $flags = [];
    private array $response = [];
    private array $media = [];

    public function __construct(array $flags)
    {
        $requestId = $flags['request_id'] ?? null;
        $this->requestId = is_string($requestId) && $requestId !== '' ? $requestId : bin2hex(random_bytes(8));
        $this->startedAt = microtime(true);
        $this->flags = $flags;
        $this->request = [
            'method' => '',
            'path' => '',
            'get' => [],
            'post' => [],
            'cookies' => [],
            'headers' => [],
        ];
        $this->user = [
            'id' => null,
            'username' => null,
            'roles' => [],
        ];
        $this->response = [
            'status' => 0,
            'content_type' => '',
        ];
        $this->media = [];
    }

    public function getRequestId(): string
    {
        return $this->requestId;
    }

    public function getStartedAt(): float
    {
        return $this->startedAt;
    }

    public function setRequest(array $data): void
    {
        $this->request = $data;
    }

    public function setUser(array $data): void
    {
        $this->user = $data;
    }

    public function setResponse(array $data): void
    {
        $this->response = $data;
    }

    public function setMedia(array $data): void
    {
        $this->media = $data;
    }

    public function addDbQuery(string $sql, int $paramsCount, float $durationMs): void
    {
        if ($this->dbCount >= 50) {
            return;
        }

        $this->dbQueries[] = [
            'sql' => $this->normalizeSql($sql),
            'params' => $paramsCount,
            'duration_ms' => $durationMs,
        ];
        $this->dbCount++;
        $this->dbTotalMs += $durationMs;
    }

    public function finalize(): void
    {
        $this->durationMs = (microtime(true) - $this->startedAt) * 1000;
        $this->memoryPeak = (int) memory_get_peak_usage(true);
    }

    public function toArray(): array
    {
        $memoryMb = $this->memoryPeak > 0 ? ($this->memoryPeak / 1048576) : 0.0;
        $topSlow = $this->dbQueries;
        usort($topSlow, static function (array $a, array $b): int {
            return ($b['duration_ms'] ?? 0) <=> ($a['duration_ms'] ?? 0);
        });
        $topSlow = array_slice($topSlow, 0, 5);

        return [
            'request_id' => $this->requestId,
            'duration_ms' => round($this->durationMs, 2),
            'memory_mb' => round($memoryMb, 2),
            'db' => [
                'count' => $this->dbCount,
                'total_ms' => round($this->dbTotalMs, 2),
                'queries' => $this->dbQueries,
                'top_slow' => $topSlow,
            ],
            'request' => $this->request,
            'user' => $this->user,
            'flags' => $this->flags,
            'response' => $this->response,
            'media' => $this->media,
        ];
    }

    private function normalizeSql(string $sql): string
    {
        $normalized = preg_replace('/\s+/', ' ', trim($sql)) ?? $sql;
        $normalized = preg_replace("/'(?:''|[^'])*'/", '?', $normalized) ?? $normalized;
        return $normalized;
    }
}
