<?php
declare(strict_types=1);

namespace Laas\Session;

use Laas\Session\Redis\RedisClient;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use SessionHandlerInterface;

final class RedisSessionHandler implements SessionHandlerInterface
{
    private LoggerInterface $logger;

    public function __construct(
        private RedisClient $client,
        private string $prefix = 'laas:sess:',
        ?LoggerInterface $logger = null
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    public function open(string $savePath, string $sessionName): bool
    {
        try {
            $this->client->connect();
            $this->client->ping();
            return true;
        } catch (\Throwable $e) {
            $this->logger->warning('Redis session handler open failed.', [
                'reason' => $e->getMessage(),
            ]);
            return false;
        }
    }

    public function close(): bool
    {
        $this->client->disconnect();
        return true;
    }

    public function read(string $id): string
    {
        try {
            $value = $this->client->get($this->key($id));
            return $value ?? '';
        } catch (\Throwable $e) {
            $this->logger->warning('Redis session read failed.', [
                'reason' => $e->getMessage(),
            ]);
            return '';
        }
    }

    public function write(string $id, string $data): bool
    {
        try {
            $ttl = (int) ini_get('session.gc_maxlifetime');
            if ($ttl <= 0) {
                $ttl = 3600;
            }
            return $this->client->setex($this->key($id), $ttl, $data);
        } catch (\Throwable $e) {
            $this->logger->warning('Redis session write failed.', [
                'reason' => $e->getMessage(),
            ]);
            return false;
        }
    }

    public function destroy(string $id): bool
    {
        try {
            $this->client->del($this->key($id));
            return true;
        } catch (\Throwable $e) {
            $this->logger->warning('Redis session destroy failed.', [
                'reason' => $e->getMessage(),
            ]);
            return false;
        }
    }

    public function gc(int $max_lifetime): int|false
    {
        return 0;
    }

    private function key(string $id): string
    {
        return $this->prefix . $id;
    }
}
