<?php
declare(strict_types=1);

namespace Laas\Session;

use Laas\Session\Redis\RedisClient;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

final class SessionFactory
{
    private LoggerInterface $logger;

    public function __construct(
        private array $sessionConfig,
        ?LoggerInterface $logger = null
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    public function create(): SessionInterface
    {
        $driver = strtolower(trim((string) ($this->sessionConfig['driver'] ?? 'native')));
        if ($driver !== 'redis') {
            return new NativeSession();
        }

        $redisConfig = $this->sessionConfig['redis'] ?? [];
        $url = (string) ($redisConfig['url'] ?? '');
        $timeout = (float) ($redisConfig['timeout'] ?? 1.5);
        $prefix = (string) ($redisConfig['prefix'] ?? 'laas:sess:');

        try {
            $client = RedisClient::fromUrl($url, $timeout);
            $client->connect();
            $client->ping();
            $handler = new RedisSessionHandler($client, $prefix, $this->logger);
            return new RedisSession($handler);
        } catch (\Throwable $e) {
            $this->logger->warning('Redis session driver unavailable, falling back to native.', [
                'reason' => $e->getMessage(),
                'redis' => $this->redactRedisUrl($url),
            ]);
            return new NativeSession();
        }
    }

    private function redactRedisUrl(string $url): string
    {
        if ($url === '') {
            return '';
        }

        $parts = parse_url($url);
        if ($parts === false) {
            return 'redis://***';
        }

        $scheme = $parts['scheme'] ?? 'redis';
        $host = $parts['host'] ?? '';
        $port = isset($parts['port']) ? ':' . $parts['port'] : '';
        $path = $parts['path'] ?? '';
        $auth = isset($parts['user']) ? ($parts['user'] . ':***@') : '';

        return $scheme . '://' . $auth . $host . $port . $path;
    }
}
