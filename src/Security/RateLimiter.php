<?php
declare(strict_types=1);

namespace Laas\Security;

use RuntimeException;

final class RateLimiter
{
    public function __construct(private string $rootPath)
    {
    }

    /** @return array{allowed: bool, remaining: int, reset: int, retry_after: int} */
    public function hit(string $group, string $ip, int $windowSeconds, int $maxRequests): array
    {
        $dir = $this->rootPath . '/storage/cache/ratelimit';
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('Unable to create ratelimit directory: ' . $dir);
        }

        $key = $group . ':' . $ip;
        $file = $dir . '/' . md5($key) . '.json';
        $now = time();

        $data = [
            'window_start' => $now,
            'count' => 0,
        ];

        $handle = fopen($file, 'c+');
        if ($handle === false) {
            throw new RuntimeException('Unable to open ratelimit file: ' . $file);
        }

        if (!flock($handle, LOCK_EX)) {
            fclose($handle);
            throw new RuntimeException('Unable to lock ratelimit file: ' . $file);
        }

        $raw = stream_get_contents($handle);
        if ($raw !== false && $raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded) && isset($decoded['window_start'], $decoded['count'])) {
                $data['window_start'] = (int) $decoded['window_start'];
                $data['count'] = (int) $decoded['count'];
            }
        }

        if ($now >= $data['window_start'] + $windowSeconds) {
            $data['window_start'] = $now;
            $data['count'] = 0;
        }

        $allowed = $data['count'] < $maxRequests;
        if ($allowed) {
            $data['count']++;
            ftruncate($handle, 0);
            rewind($handle);
            fwrite($handle, json_encode($data));
            fflush($handle);
        }

        flock($handle, LOCK_UN);
        fclose($handle);

        $reset = $data['window_start'] + $windowSeconds;
        $remaining = max(0, $maxRequests - $data['count']);
        $retryAfter = max(0, $reset - $now);

        return [
            'allowed' => $allowed,
            'remaining' => $remaining,
            'reset' => $reset,
            'retry_after' => $retryAfter,
        ];
    }
}
