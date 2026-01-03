<?php
declare(strict_types=1);

namespace Laas\Support;

use Psr\Log\LoggerInterface;

final class LogSpamGuard
{
    private string $path;
    private int $ttlSeconds;

    public function __construct(string $rootPath, int $ttlSeconds = 600)
    {
        $dir = $rootPath . '/storage/health';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $this->path = $dir . '/log_spam_guard.json';
        $this->ttlSeconds = $ttlSeconds;
    }

    public function logOnce(LoggerInterface $logger, string $key, string $message, array $context = []): void
    {
        $now = time();
        $state = $this->readState();
        $last = (int) ($state[$key] ?? 0);
        if ($last > 0 && ($now - $last) <= $this->ttlSeconds) {
            return;
        }

        $state[$key] = $now;
        $this->writeState($state);
        $logger->error($message, $context);
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
