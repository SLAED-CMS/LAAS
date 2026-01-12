<?php
declare(strict_types=1);

namespace Laas\Http;

final class TrustProxy
{
    private const DEFAULT_HEADERS = ['x-forwarded-for', 'x-forwarded-proto'];

    public static function resolveClientIp(array $server, array $headers): string
    {
        $remote = self::normalizeIp((string) ($server['REMOTE_ADDR'] ?? ''));

        if (!self::isEnabled() || !self::isTrustedProxy($remote)) {
            return $remote;
        }

        $trustedHeaders = self::trustedHeaders();
        if (!in_array('x-forwarded-for', $trustedHeaders, true)) {
            return $remote;
        }

        $headerValue = self::headerValue($headers, 'x-forwarded-for');
        if ($headerValue === null || $headerValue === '') {
            return $remote;
        }

        $ips = self::parseIpList($headerValue);
        if ($ips === []) {
            return $remote;
        }

        for ($i = count($ips) - 1; $i >= 0; $i--) {
            if (self::isPublicIp($ips[$i])) {
                return $ips[$i];
            }
        }

        return $ips[0];
    }

    public static function resolveHttps(array $server, array $headers): bool
    {
        $default = self::serverHttps($server);
        $remote = self::normalizeIp((string) ($server['REMOTE_ADDR'] ?? ''));

        if (!self::isEnabled() || !self::isTrustedProxy($remote)) {
            return $default;
        }

        $trustedHeaders = self::trustedHeaders();
        if (!in_array('x-forwarded-proto', $trustedHeaders, true)) {
            return $default;
        }

        $headerValue = self::headerValue($headers, 'x-forwarded-proto');
        if ($headerValue === null || $headerValue === '') {
            return $default;
        }

        $proto = strtolower(trim(explode(',', $headerValue, 2)[0]));
        if ($proto === 'https') {
            return true;
        }
        if ($proto === 'http') {
            return false;
        }

        return $default;
    }

    private static function isEnabled(): bool
    {
        $value = self::env('TRUST_PROXY_ENABLED');
        if ($value === '') {
            return false;
        }

        $parsed = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        return $parsed ?? false;
    }

    /** @return array<int, string> */
    private static function trustedProxyIps(): array
    {
        return self::envList('TRUST_PROXY_IPS', []);
    }

    /** @return array<int, string> */
    private static function trustedHeaders(): array
    {
        $headers = self::envList('TRUST_PROXY_HEADERS', self::DEFAULT_HEADERS);
        if ($headers === []) {
            $headers = self::DEFAULT_HEADERS;
        }
        $normalized = [];
        foreach ($headers as $header) {
            $header = strtolower(trim($header));
            if ($header !== '') {
                $normalized[] = $header;
            }
        }

        return array_values(array_unique($normalized));
    }

    private static function isTrustedProxy(string $remote): bool
    {
        if ($remote === '0.0.0.0') {
            return false;
        }

        foreach (self::trustedProxyIps() as $entry) {
            $entry = trim($entry);
            if ($entry === '') {
                continue;
            }
            if (strpos($entry, '/') !== false) {
                if (self::cidrMatch($remote, $entry)) {
                    return true;
                }
                continue;
            }
            if ($remote === $entry) {
                return true;
            }
        }

        return false;
    }

    private static function normalizeIp(string $ip): string
    {
        $ip = trim($ip);
        if ($ip === '' || filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return '0.0.0.0';
        }

        return $ip;
    }

    private static function serverHttps(array $server): bool
    {
        $https = strtolower((string) ($server['HTTPS'] ?? ''));
        if ($https !== '' && $https !== 'off' && $https !== '0') {
            return true;
        }

        $scheme = strtolower((string) ($server['REQUEST_SCHEME'] ?? ''));
        if ($scheme === 'https') {
            return true;
        }

        $port = (string) ($server['SERVER_PORT'] ?? '');
        return $port === '443';
    }

    /** @return array<int, string> */
    private static function parseIpList(string $value): array
    {
        $items = array_map('trim', explode(',', $value));
        $ips = [];
        foreach ($items as $item) {
            if ($item === '') {
                continue;
            }
            if (filter_var($item, FILTER_VALIDATE_IP) === false) {
                continue;
            }
            $ips[] = $item;
        }

        return $ips;
    }

    private static function isPublicIp(string $ip): bool
    {
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
    }

    private static function cidrMatch(string $ip, string $cidr): bool
    {
        $parts = explode('/', $cidr, 2);
        if (count($parts) !== 2) {
            return false;
        }
        [$subnet, $prefix] = $parts;
        if ($subnet === '' || $prefix === '') {
            return false;
        }

        $ipBin = inet_pton($ip);
        $subnetBin = inet_pton($subnet);
        if ($ipBin === false || $subnetBin === false) {
            return false;
        }
        if (strlen($ipBin) !== strlen($subnetBin)) {
            return false;
        }

        $bits = (int) $prefix;
        $maxBits = strlen($ipBin) * 8;
        if ($bits < 0 || $bits > $maxBits) {
            return false;
        }
        if ($bits === 0) {
            return true;
        }

        $bytes = intdiv($bits, 8);
        $remainder = $bits % 8;

        if ($bytes > 0 && substr_compare($ipBin, $subnetBin, 0, $bytes) !== 0) {
            return false;
        }

        if ($remainder === 0) {
            return true;
        }

        $mask = (0xFF << (8 - $remainder)) & 0xFF;
        return (ord($ipBin[$bytes]) & $mask) === (ord($subnetBin[$bytes]) & $mask);
    }

    private static function headerValue(array $headers, string $name): ?string
    {
        $name = strtolower($name);
        foreach ($headers as $key => $value) {
            if (strtolower((string) $key) === $name) {
                return is_string($value) ? $value : null;
            }
        }

        return null;
    }

    /** @return array<int, string> */
    private static function envList(string $key, array $default): array
    {
        $value = self::env($key);
        if ($value === '') {
            return $default;
        }

        $parts = array_filter(array_map('trim', explode(',', $value)));
        return $parts !== [] ? array_values($parts) : $default;
    }

    private static function env(string $key): string
    {
        $value = $_ENV[$key] ?? '';
        if ($value === '' && function_exists('getenv')) {
            $envValue = getenv($key);
            if ($envValue !== false) {
                $value = (string) $envValue;
            }
        }

        return (string) $value;
    }
}
