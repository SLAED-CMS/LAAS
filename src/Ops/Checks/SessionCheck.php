<?php
declare(strict_types=1);

namespace Laas\Ops\Checks;

use Laas\Session\Redis\RedisClient;

final class SessionCheck
{
    public function __construct(private array $sessionConfig)
    {
    }

    /** @return array{code: int, message: string} */
    public function run(): array
    {
        $driver = strtolower(trim((string) ($this->sessionConfig['driver'] ?? 'native')));
        if ($driver !== 'redis') {
            return ['code' => 0, 'message' => 'native session: OK'];
        }

        $redis = $this->sessionConfig['redis'] ?? [];
        $url = (string) ($redis['url'] ?? '');
        $timeout = (float) ($redis['timeout'] ?? 1.5);

        try {
            $client = RedisClient::fromUrl($url, $timeout);
            $client->connect();
            $client->ping();
        } catch (\Throwable) {
            $target = $this->formatTarget($url);
            $suffix = $target !== '' ? (' (' . $target . ')') : '';
            return ['code' => 2, 'message' => 'redis session: FAIL (fallback native)' . $suffix];
        }

        $target = $this->formatTarget($url);
        $suffix = $target !== '' ? (' (' . $target . ')') : '';
        return ['code' => 0, 'message' => 'redis session: OK' . $suffix];
    }

    private function formatTarget(string $url): string
    {
        $parts = parse_url($url);
        if ($parts === false) {
            return '';
        }

        $host = (string) ($parts['host'] ?? '');
        if ($host === '') {
            return '';
        }

        $port = (int) ($parts['port'] ?? 6379);
        $db = 0;
        if (!empty($parts['path'])) {
            $path = ltrim((string) $parts['path'], '/');
            if ($path !== '' && ctype_digit($path)) {
                $db = (int) $path;
            }
        }

        return $host . ':' . $port . '/' . $db;
    }
}
