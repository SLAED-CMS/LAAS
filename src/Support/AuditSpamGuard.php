<?php

declare(strict_types=1);

namespace Laas\Support;

final class AuditSpamGuard
{
    private string $path;
    private int $ttlSeconds;

    public function __construct(string $rootPath, int $ttlSeconds = 60)
    {
        $dir = rtrim($rootPath, '/\\') . '/storage/health';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $this->path = $dir . '/audit_spam_guard.json';
        $this->ttlSeconds = $ttlSeconds;
    }

    public function shouldLog(string $key): bool
    {
        $now = time();
        $state = $this->readState();
        $last = (int) ($state[$key] ?? 0);
        if ($last > 0 && ($now - $last) <= $this->ttlSeconds) {
            return false;
        }

        $state[$key] = $now;
        $this->writeState($state);

        return true;
    }

    /** @return array<string, int> */
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
