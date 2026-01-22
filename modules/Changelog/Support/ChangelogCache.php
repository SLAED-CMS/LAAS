<?php

declare(strict_types=1);

namespace Laas\Modules\Changelog\Support;

final class ChangelogCache
{
    private string $dir;

    public function __construct(string $rootPath)
    {
        $this->dir = rtrim($rootPath, '/\\') . '/storage/cache/changelog';
    }

    public function buildKey(string $source, string $branch, int $page, int $perPage, bool $merges): string
    {
        return 'changelog:v1:' . $source . ':' . $branch . ':' . $page . ':' . $perPage . ':' . ($merges ? '1' : '0');
    }

    public function get(string $key, bool $allowExpired = false): mixed
    {
        $path = $this->pathForKey($key);
        if (!is_file($path)) {
            return null;
        }

        $raw = @file_get_contents($path);
        if ($raw === false) {
            return null;
        }

        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return null;
        }

        $expires = (int) ($data['expires_at'] ?? 0);
        if ($expires > 0 && $expires < time()) {
            if (!$allowExpired) {
                return null;
            }
        }

        $payload = (string) ($data['value'] ?? '');
        if ($payload === '') {
            return null;
        }

        return @unserialize($payload, ['allowed_classes' => false]);
    }

    public function set(string $key, mixed $value, int $ttl): bool
    {
        if (!$this->ensureDir()) {
            return false;
        }

        $ttl = $ttl > 0 ? $ttl : 300;
        $expires = time() + $ttl;
        $data = [
            'expires_at' => $expires,
            'value' => serialize($value),
        ];

        $tmp = $this->dir . '/' . bin2hex(random_bytes(6)) . '.tmp';
        $written = @file_put_contents($tmp, json_encode($data, JSON_UNESCAPED_SLASHES), LOCK_EX);
        if ($written === false) {
            @unlink($tmp);
            return false;
        }

        return @rename($tmp, $this->pathForKey($key));
    }

    public function clear(): int
    {
        if (!is_dir($this->dir)) {
            return 0;
        }

        $files = glob($this->dir . '/*.cache') ?: [];
        $count = 0;
        foreach ($files as $file) {
            if (@unlink($file)) {
                $count++;
            }
        }

        return $count;
    }

    /** @return resource|null */
    public function acquireLock(string $key, int $timeoutSeconds)
    {
        if (!$this->ensureDir()) {
            return null;
        }

        $lockPath = $this->pathForKey($key) . '.lock';
        $handle = @fopen($lockPath, 'c+');
        if (!is_resource($handle)) {
            return null;
        }

        $end = microtime(true) + $timeoutSeconds;
        do {
            if (flock($handle, LOCK_EX | LOCK_NB)) {
                return $handle;
            }
            usleep(100000);
        } while (microtime(true) < $end);

        fclose($handle);
        return null;
    }

    /** @param resource $handle */
    public function releaseLock($handle): void
    {
        if (!is_resource($handle)) {
            return;
        }
        flock($handle, LOCK_UN);
        fclose($handle);
    }

    private function pathForKey(string $key): string
    {
        $hash = sha1($key);
        return $this->dir . '/' . $hash . '.cache';
    }

    private function ensureDir(): bool
    {
        if (is_dir($this->dir)) {
            return true;
        }

        return mkdir($this->dir, 0775, true) || is_dir($this->dir);
    }
}
