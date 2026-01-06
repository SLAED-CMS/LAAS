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
    public function hit(string $group, string $ip, int $windowSeconds, int $maxRequests, ?int $burst = null): array
    {
        $dir = $this->rootPath . '/storage/cache/ratelimit';
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('Unable to create ratelimit directory: ' . $dir);
        }

        $key = $group . ':' . $ip;
        $file = $dir . '/' . md5($key) . '.json';
        $now = time();

        if ($maxRequests <= 0) {
            return [
                'allowed' => false,
                'remaining' => 0,
                'reset' => $now + max(1, $windowSeconds),
                'retry_after' => max(1, $windowSeconds),
            ];
        }

        if ($burst !== null && $burst <= 0) {
            $burst = null;
        }

        $data = [
            'window_start' => $now,
            'count' => 0,
            'tokens' => $burst ?? 0,
            'updated_at' => $now,
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
            if (is_array($decoded)) {
                $data['window_start'] = isset($decoded['window_start']) ? (int) $decoded['window_start'] : $data['window_start'];
                $data['count'] = isset($decoded['count']) ? (int) $decoded['count'] : $data['count'];
                $data['tokens'] = isset($decoded['tokens']) ? (float) $decoded['tokens'] : $data['tokens'];
                $data['updated_at'] = isset($decoded['updated_at']) ? (int) $decoded['updated_at'] : $data['updated_at'];
            }
        }

        if ($burst !== null) {
            $ratePerSecond = $maxRequests / max(1, $windowSeconds);
            $elapsed = max(0, $now - (int) $data['updated_at']);
            $tokens = min($burst, (float) $data['tokens'] + ($elapsed * $ratePerSecond));

            $allowed = $tokens >= 1.0;
            if ($allowed) {
                $tokens -= 1.0;
            }

            $data['tokens'] = $tokens;
            $data['updated_at'] = $now;

            ftruncate($handle, 0);
            rewind($handle);
            fwrite($handle, json_encode($data));
            fflush($handle);

            $remaining = (int) floor($tokens);
            $retryAfter = $allowed ? 0 : (int) ceil((1.0 - $tokens) / $ratePerSecond);
            $reset = (int) ($now + ceil(($burst - $tokens) / $ratePerSecond));

            flock($handle, LOCK_UN);
            fclose($handle);

            return [
                'allowed' => $allowed,
                'remaining' => $remaining,
                'reset' => $reset,
                'retry_after' => $retryAfter,
            ];
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
