<?php
declare(strict_types=1);

namespace Laas\Session;

use Laas\Session\Redis\RedisClient;
use Laas\Session\Redis\RedisSessionFailover;
use Laas\Support\UrlSanitizer;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

final class SessionFactory
{
    private LoggerInterface $logger;
    private string $rootPath;
    private RedisSessionFailover $failover;

    public function __construct(
        private array $sessionConfig,
        ?LoggerInterface $logger = null,
        ?string $rootPath = null
    ) {
        $this->logger = $logger ?? new NullLogger();
        $this->rootPath = $rootPath ?? dirname(__DIR__, 2);
        $this->failover = new RedisSessionFailover($this->rootPath);
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
            if ($this->failover->hasRecentFailure()) {
                $this->logger->warning('Redis session driver recently failed, falling back to native.', [
                    'redis' => UrlSanitizer::sanitizeRedisUrl($url),
                ]);
                return new NativeSession();
            }

            $client = RedisClient::fromUrl($url, $timeout);
            $client->connect();
            $client->ping();
            $this->failover->clearFailure();
            $handler = new RedisSessionHandler($client, $prefix, $this->logger, $this->failover);
            return new RedisSession($handler);
        } catch (\Throwable $e) {
            $this->logger->warning('Redis session driver unavailable, falling back to native.', [
                'reason' => $e->getMessage(),
                'redis' => UrlSanitizer::sanitizeRedisUrl($url),
            ]);
            $this->failover->markFailure();
            return new NativeSession();
        }
    }
}
