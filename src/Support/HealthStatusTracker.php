<?php
declare(strict_types=1);

namespace Laas\Support;

use Psr\Log\LoggerInterface;

final class HealthStatusTracker
{
    private string $path;
    private LoggerInterface $logger;
    private int $ttlSeconds;

    public function __construct(string $rootPath, LoggerInterface $logger, int $ttlSeconds = 600)
    {
        $dir = $rootPath . '/storage/health';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $this->path = $dir . '/health_state.json';
        $this->logger = $logger;
        $this->ttlSeconds = $ttlSeconds;
    }

    public function logHealthTransition(bool $ok): void
    {
        $now = time();
        $state = $this->readState();
        $current = $ok ? 'ok' : 'degraded';
        $last = $state['status'] ?? '';
        $lastTs = (int) ($state['ts'] ?? 0);
        $stale = $lastTs <= 0 || ($now - $lastTs) > $this->ttlSeconds;

        if ($stale || $last !== $current) {
            if ($current === 'degraded') {
                $this->logger->warning('Health degraded');
            } else {
                $this->logger->info('Health recovered');
            }
        }

        $this->writeState([
            'status' => $current,
            'ts' => $now,
        ]);
    }

    /** @return array<string, mixed> */
    private function readState(): array
    {
        if (!is_file($this->path)) {
            return [];
        }

        $fp = @fopen($this->path, 'rb');
        if ($fp === false) {
            return [];
        }

        flock($fp, LOCK_SH);
        $raw = stream_get_contents($fp) ?: '';
        flock($fp, LOCK_UN);
        fclose($fp);

        $data = json_decode($raw, true);
        return is_array($data) ? $data : [];
    }

    private function writeState(array $state): void
    {
        $fp = @fopen($this->path, 'c+');
        if ($fp === false) {
            return;
        }

        flock($fp, LOCK_EX);
        ftruncate($fp, 0);
        fwrite($fp, json_encode($state, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        fflush($fp);
        flock($fp, LOCK_UN);
        fclose($fp);
    }
}
