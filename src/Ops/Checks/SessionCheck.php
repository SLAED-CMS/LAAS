<?php

declare(strict_types=1);

namespace Laas\Ops\Checks;

use Laas\Session\Redis\RedisClient;
use Laas\Support\SessionConfigValidator;

final class SessionCheck
{
    private string $rootPath;
    private SessionConfigValidator $validator;

    public function __construct(
        private array $sessionConfig,
        ?string $rootPath = null,
        ?SessionConfigValidator $validator = null
    ) {
        $this->rootPath = $rootPath ?? dirname(__DIR__, 3);
        $this->validator = $validator ?? new SessionConfigValidator();
    }

    /** @return array{code: int, message: string} */
    public function run(): array
    {
        $messages = [];
        $code = 0;

        if (!$this->storageWritable()) {
            $messages[] = 'session storage: FAIL';
            $code = 1;
        } else {
            $messages[] = 'session storage: OK';
        }

        $warnings = $this->validator->warnings($this->sessionConfig);
        if ($warnings !== []) {
            $messages[] = 'session config: WARN (' . implode(', ', $warnings) . ')';
            if ($code === 0) {
                $code = 2;
            }
        }

        $driver = strtolower(trim((string) ($this->sessionConfig['driver'] ?? 'native')));
        if ($driver !== 'redis') {
            $messages[] = 'native session: OK';
            return [
                'code' => $code,
                'message' => implode("\n", $messages),
            ];
        }

        $redis = $this->sessionConfig['redis'] ?? [];
        $url = (string) ($redis['url'] ?? '');
        $timeout = (float) ($redis['timeout'] ?? 1.5);

        try {
            $client = RedisClient::fromUrl($url, $timeout);
            $client->connect();
            $client->ping();
        } catch (\Throwable) {
            $target = self::formatTarget($url);
            $suffix = $target !== '' ? (' (' . $target . ')') : '';
            $messages[] = 'redis session: FAIL (fallback native)' . $suffix;
            if ($code === 0) {
                $code = 2;
            }
            return [
                'code' => $code,
                'message' => implode("\n", $messages),
            ];
        }

        $target = self::formatTarget($url);
        $suffix = $target !== '' ? (' (' . $target . ')') : '';
        $messages[] = 'redis session: OK' . $suffix;

        return [
            'code' => $code,
            'message' => implode("\n", $messages),
        ];
    }

    public static function formatTarget(string $url): string
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

    private function storageWritable(): bool
    {
        $dir = $this->rootPath . '/storage/sessions';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        return is_dir($dir) && is_writable($dir);
    }
}
