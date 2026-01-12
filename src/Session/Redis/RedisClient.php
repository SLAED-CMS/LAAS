<?php
declare(strict_types=1);

namespace Laas\Session\Redis;

use RuntimeException;

final class RedisClient
{
    private const MAX_PAYLOAD_BYTES = 2097152;
    private $stream = null;
    private bool $connected = false;

    public function __construct(
        private string $host,
        private int $port,
        private float $timeout,
        private ?string $user = null,
        private ?string $password = null,
        private int $database = 0
    ) {
    }

    public static function fromUrl(string $url, float $timeout): self
    {
        if ($url === '') {
            throw new RuntimeException('Redis URL is empty.');
        }

        $parts = parse_url($url);
        if ($parts === false || ($parts['host'] ?? '') === '') {
            throw new RuntimeException('Invalid Redis URL.');
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? 'redis'));
        if ($scheme !== 'redis') {
            throw new RuntimeException('Unsupported Redis scheme.');
        }

        $host = (string) $parts['host'];
        $port = (int) ($parts['port'] ?? 6379);
        if ($port <= 0) {
            throw new RuntimeException('Invalid Redis port.');
        }

        $user = isset($parts['user']) ? (string) $parts['user'] : null;
        $pass = isset($parts['pass']) ? (string) $parts['pass'] : null;
        $db = 0;
        if (!empty($parts['path'])) {
            $path = ltrim((string) $parts['path'], '/');
            if ($path !== '' && ctype_digit($path)) {
                $db = (int) $path;
            }
        }

        return new self($host, $port, $timeout, $user, $pass, $db);
    }

    public function connect(): void
    {
        if ($this->connected && is_resource($this->stream)) {
            return;
        }

        $errno = 0;
        $errstr = '';
        $stream = @fsockopen($this->host, $this->port, $errno, $errstr, $this->timeout);
        if (!is_resource($stream)) {
            throw new RuntimeException('Redis connection failed.');
        }

        $this->stream = $stream;
        $this->connected = true;

        $seconds = (int) floor($this->timeout);
        $useconds = (int) (($this->timeout - $seconds) * 1000000);
        stream_set_timeout($this->stream, $seconds, $useconds);

        if ($this->password !== null && $this->password !== '') {
            if ($this->user !== null && $this->user !== '') {
                $this->command(['AUTH', $this->user, $this->password]);
            } else {
                $this->command(['AUTH', $this->password]);
            }
        }

        if ($this->database > 0) {
            $this->command(['SELECT', (string) $this->database]);
        }
    }

    public function disconnect(): void
    {
        if (is_resource($this->stream)) {
            fclose($this->stream);
        }
        $this->stream = null;
        $this->connected = false;
    }

    public function ping(): bool
    {
        return $this->command(['PING']) === 'PONG';
    }

    public function get(string $key): ?string
    {
        $result = $this->command(['GET', $key]);
        if ($result === null) {
            return null;
        }

        return (string) $result;
    }

    public function setex(string $key, int $ttl, string $value): bool
    {
        $result = $this->command(['SETEX', $key, (string) $ttl, $value]);
        return $result === 'OK';
    }

    public function del(string $key): int
    {
        return (int) $this->command(['DEL', $key]);
    }

    public function exists(string $key): bool
    {
        return (int) $this->command(['EXISTS', $key]) === 1;
    }

    public static function buildCommand(array $args): string
    {
        $total = 0;
        foreach ($args as $arg) {
            $arg = (string) $arg;
            $len = strlen($arg);
            if ($len > self::MAX_PAYLOAD_BYTES) {
                throw new RuntimeException('Redis payload too large.');
            }
            $total += $len;
            if ($total > self::MAX_PAYLOAD_BYTES) {
                throw new RuntimeException('Redis payload too large.');
            }
        }

        $out = '*' . count($args) . "\r\n";
        foreach ($args as $arg) {
            $arg = (string) $arg;
            $out .= '$' . strlen($arg) . "\r\n" . $arg . "\r\n";
        }
        return $out;
    }

    public static function parseResponse(string $payload): mixed
    {
        $stream = fopen('php://memory', 'r+');
        if ($stream === false) {
            throw new RuntimeException('Unable to open memory stream.');
        }

        fwrite($stream, $payload);
        rewind($stream);

        return self::readResponseFromStream($stream);
    }

    private function command(array $args): mixed
    {
        $attempts = 0;
        do {
            try {
                return $this->executeCommand($args);
            } catch (\Throwable $e) {
                $attempts++;
                $this->disconnect();
                if ($attempts > 1) {
                    throw $e;
                }
            }
        } while (true);
    }

    private function executeCommand(array $args): mixed
    {
        $this->connect();

        $payload = self::buildCommand($args);
        $this->writeAll($payload);

        return self::readResponseFromStream($this->stream);
    }

    private static function readResponseFromStream($stream): mixed
    {
        $line = self::readLine($stream);
        if ($line === '') {
            throw new RuntimeException('Redis response is empty.');
        }

        $type = $line[0];
        $payload = substr($line, 1);

        switch ($type) {
            case '+':
                return $payload;
            case '-':
                throw new RuntimeException('Redis error: ' . $payload);
            case ':':
                return (int) $payload;
            case '$':
                $length = (int) $payload;
                if ($length === -1) {
                    return null;
                }
                if ($length < 0) {
                    throw new RuntimeException('Invalid bulk length.');
                }
                if ($length > self::MAX_PAYLOAD_BYTES) {
                    throw new RuntimeException('Redis bulk payload too large.');
                }
                $data = self::readBytes($stream, $length);
                self::readBytes($stream, 2);
                return $data;
            case '*':
                $count = (int) $payload;
                if ($count === -1) {
                    return null;
                }
                if ($count < 0) {
                    throw new RuntimeException('Invalid array length.');
                }
                $items = [];
                for ($i = 0; $i < $count; $i++) {
                    $items[] = self::readResponseFromStream($stream);
                }
                return $items;
            default:
                throw new RuntimeException('Unexpected Redis response.');
        }
    }

    private static function readLine($stream): string
    {
        $line = fgets($stream);
        if ($line === false) {
            throw new RuntimeException('Redis read failed.');
        }

        self::assertStreamHealthy($stream);

        return rtrim($line, "\r\n");
    }

    private static function readBytes($stream, int $length): string
    {
        $data = '';
        while (strlen($data) < $length) {
            $chunk = fread($stream, $length - strlen($data));
            if ($chunk === false || $chunk === '') {
                self::assertStreamHealthy($stream);
                throw new RuntimeException('Redis read failed.');
            }
            $data .= $chunk;
        }

        self::assertStreamHealthy($stream);

        return $data;
    }

    private function writeAll(string $payload): void
    {
        $length = strlen($payload);
        if ($length > self::MAX_PAYLOAD_BYTES) {
            throw new RuntimeException('Redis payload too large.');
        }

        $offset = 0;
        while ($offset < $length) {
            $chunk = substr($payload, $offset);
            $written = fwrite($this->stream, $chunk);
            if ($written === false || $written === 0) {
                self::assertStreamHealthy($this->stream);
                throw new RuntimeException('Redis write failed.');
            }
            $offset += $written;
        }

        self::assertStreamHealthy($this->stream);
    }

    private static function assertStreamHealthy($stream): void
    {
        $meta = stream_get_meta_data($stream);
        if (!empty($meta['timed_out'])) {
            throw new RuntimeException('Redis read timed out.');
        }
    }
}
