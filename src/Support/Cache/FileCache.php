<?php
declare(strict_types=1);

namespace Laas\Support\Cache;

final class FileCache implements CacheInterface
{
    private string $dir;
    private string $prefix;
    private int $defaultTtl;

    public function __construct(string $dir, string $prefix = 'laas', int $defaultTtl = 300)
    {
        $this->dir = rtrim($dir, '/\\');
        $this->prefix = $prefix;
        $this->defaultTtl = $defaultTtl > 0 ? $defaultTtl : 300;
    }

    public function get(string $key): mixed
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
            @unlink($path);
            return null;
        }

        $payload = (string) ($data['value'] ?? '');
        if ($payload === '') {
            return null;
        }

        return @unserialize($payload, ['allowed_classes' => false]);
    }

    public function set(string $key, mixed $value, ?int $ttl = null): bool
    {
        if (!$this->ensureDir()) {
            return false;
        }

        $ttl = $ttl ?? $this->defaultTtl;
        $expires = $ttl > 0 ? (time() + $ttl) : 0;
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

    public function delete(string $key): bool
    {
        $path = $this->pathForKey($key);
        if (!is_file($path)) {
            return true;
        }

        return @unlink($path);
    }

    private function pathForKey(string $key): string
    {
        $hash = sha1($this->prefix . ':' . $key);
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
