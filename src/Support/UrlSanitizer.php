<?php
declare(strict_types=1);

namespace Laas\Support;

final class UrlSanitizer
{
    public static function sanitizeRedisUrl(string $url): string
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

        $auth = '';
        if (array_key_exists('user', $parts) || array_key_exists('pass', $parts)) {
            $user = array_key_exists('user', $parts) ? '***' : '';
            $pass = array_key_exists('pass', $parts) ? '***' : '';
            if ($user !== '' && $pass !== '') {
                $auth = $user . ':' . $pass . '@';
            } elseif ($user !== '') {
                $auth = $user . '@';
            } elseif ($pass !== '') {
                $auth = '***@';
            }
        }

        return $scheme . '://' . $auth . $host . $port . $path;
    }
}
