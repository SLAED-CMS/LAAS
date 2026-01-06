<?php
declare(strict_types=1);

namespace Laas\Api;

use Laas\Support\Cache\CacheFactory;
use Laas\Support\Cache\CacheInterface;

final class ApiCache
{
    private const VERSION_DEFAULT = 1;
    private const PAGES_VERSION_KEY = 'api:v1:pages:version';
    private const MEDIA_VERSION_KEY = 'api:v1:media:version';

    private CacheInterface $cache;
    private int $ttl;

    public function __construct(?CacheInterface $cache = null, ?int $ttl = null, ?string $rootPath = null)
    {
        $root = $rootPath ?? dirname(__DIR__, 2);
        $this->cache = $cache ?? CacheFactory::create($root);
        $this->ttl = $ttl ?? 60;
    }

    public function get(string $key): mixed
    {
        return $this->cache->get($key);
    }

    public function set(string $key, mixed $value, ?int $ttl = null): bool
    {
        return $this->cache->set($key, $value, $ttl ?? $this->ttl);
    }

    public function pagesKey(array $filters, int $page, int $perPage): string
    {
        $version = $this->version(self::PAGES_VERSION_KEY);
        $suffix = $this->hashKey($filters, $page, $perPage);
        return 'api:v1:pages:' . $version . ':' . $suffix;
    }

    public function mediaKey(array $filters, int $page, int $perPage): string
    {
        $version = $this->version(self::MEDIA_VERSION_KEY);
        $suffix = $this->hashKey($filters, $page, $perPage);
        return 'api:v1:media:' . $version . ':' . $suffix;
    }

    public function menuKey(string $name, string $locale): string
    {
        $name = $name !== '' ? $name : 'menu';
        $locale = $locale !== '' ? $locale : 'en';

        return 'api:v1:menu:' . $name . ':' . $locale;
    }

    public static function pagesVersionKey(): string
    {
        return self::PAGES_VERSION_KEY;
    }

    public static function mediaVersionKey(): string
    {
        return self::MEDIA_VERSION_KEY;
    }

    private function version(string $key): int
    {
        $value = $this->cache->get($key);
        if (is_int($value) && $value > 0) {
            return $value;
        }
        if (is_string($value) && ctype_digit($value)) {
            return (int) $value;
        }

        return self::VERSION_DEFAULT;
    }

    private function hashKey(array $filters, int $page, int $perPage): string
    {
        $payload = [
            'filters' => $filters,
            'page' => $page,
            'per_page' => $perPage,
        ];
        $encoded = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($encoded === false) {
            $encoded = (string) $page . ':' . (string) $perPage;
        }

        return md5($encoded);
    }
}
