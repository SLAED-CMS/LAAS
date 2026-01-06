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
        if ($key === 'settings:v1:all' && $this->shouldLog()) {
            error_log("[FileCache] get({$key}) - path: {$path}, exists: " . (is_file($path) ? 'YES' : 'NO'));
        }
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

        $result = @unserialize($payload, ['allowed_classes' => false]);
        if ($key === 'settings:v1:all' && $this->shouldLog()) {
            $keys = is_array($result) && isset($result['values']) ? array_keys($result['values']) : [];
            error_log("[FileCache] get({$key}) - returning data with keys: " . implode(', ', $keys));
        }
        return $result;
    }

    public function set(string $key, mixed $value, ?int $ttl = null): bool
    {
        if ($key === 'settings:v1:all' && $this->shouldLog()) {
            $keys = is_array($value) && isset($value['values']) ? array_keys($value['values']) : [];
            error_log("[FileCache] set({$key}) START - keys: " . implode(', ', $keys));
        }

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

        $result = @rename($tmp, $this->pathForKey($key));
        if ($key === 'settings:v1:all' && $this->shouldLog()) {
            error_log("[FileCache] set({$key}) - result: " . ($result ? 'OK' : 'FAILED'));
        }
        return $result;
    }

    public function delete(string $key): bool
    {
        $path = $this->pathForKey($key);
        if ($this->shouldLog()) {
            error_log("[FileCache] delete({$key}) - path: {$path}");
        }
        if (!is_file($path)) {
            if ($this->shouldLog()) {
                error_log("[FileCache] delete({$key}) - file does not exist, returning true");
            }
            return true;
        }

        $result = @unlink($path);
        if ($this->shouldLog()) {
            error_log("[FileCache] delete({$key}) - unlink result: " . ($result ? 'OK' : 'FAILED'));
            if ($result && is_file($path)) {
                error_log("[FileCache] delete({$key}) - WARNING: file still exists after unlink!");
            }
        }
        return $result;
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

    private function shouldLog(): bool
    {
        $env = strtolower((string) getenv('APP_ENV'));
        if ($env === 'test') {
            return false;
        }
        if (getenv('CI') === 'true') {
            return false;
        }
        return true;
    }
}
