<?php
declare(strict_types=1);

namespace Laas\Support;

final class UrlValidator
{
    public const REASON_INVALID_URL = 'url_invalid';
    public const REASON_SCHEME_NOT_ALLOWED = 'url_scheme_not_allowed';
    public const REASON_MISSING_HOST = 'url_missing_host';
    public const REASON_USERINFO_NOT_ALLOWED = 'url_userinfo_not_allowed';
    public const REASON_PORT_NOT_ALLOWED = 'url_port_not_allowed';
    public const REASON_HOST_NOT_ALLOWED = 'url_host_not_allowed';
    public const REASON_HOST_BLOCKED = 'url_host_blocked';
    public const REASON_IP_LITERAL_NOT_ALLOWED = 'url_ip_literal_not_allowed';
    public const REASON_DNS_LOOKUP_FAILED = 'url_dns_lookup_failed';
    public const REASON_IP_BLOCKED = 'url_ip_blocked';

    public static function isSafe(string $url): bool
    {
        if (preg_match('/[\x00-\x1F\x7F]/', $url) === 1) {
            return false;
        }

        $value = trim($url);
        if ($value === '') {
            return false;
        }

        if (str_starts_with($value, '/')) {
            return !str_starts_with($value, '//');
        }

        $parts = parse_url($value);
        if (!is_array($parts)) {
            return false;
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        if ($scheme === 'http' || $scheme === 'https') {
            return true;
        }

        if (in_array($scheme, ['javascript', 'data', 'vbscript'], true)) {
            return false;
        }

        return false;
    }

    public static function validateHttpUrl(string $url): UrlValidationResult
    {
        return self::validateHttpUrlWithPolicy($url, new UrlPolicy());
    }

    public static function validateHttpUrlWithPolicy(string $url, UrlPolicy $policy): UrlValidationResult
    {
        if (preg_match('/[\x00-\x1F\x7F]/', $url) === 1) {
            return new UrlValidationResult(false, self::REASON_INVALID_URL);
        }

        $value = trim($url);
        if ($value === '') {
            return new UrlValidationResult(false, self::REASON_INVALID_URL);
        }

        $parts = parse_url($value);
        if (!is_array($parts)) {
            return new UrlValidationResult(false, self::REASON_INVALID_URL);
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        if ($scheme === '') {
            return new UrlValidationResult(false, self::REASON_SCHEME_NOT_ALLOWED);
        }

        if (!self::isAllowedScheme($scheme, $policy->allowedSchemes())) {
            return new UrlValidationResult(false, self::REASON_SCHEME_NOT_ALLOWED);
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            return new UrlValidationResult(false, self::REASON_USERINFO_NOT_ALLOWED);
        }

        if (isset($parts['port'])) {
            $port = (int) $parts['port'];
            if ($port < 1 || $port > 65535) {
                return new UrlValidationResult(false, self::REASON_PORT_NOT_ALLOWED);
            }
        }

        $host = strtolower((string) ($parts['host'] ?? ''));
        $host = rtrim($host, '.');
        if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
            $host = substr($host, 1, -1);
        }
        if ($host === '') {
            return new UrlValidationResult(false, self::REASON_MISSING_HOST);
        }

        $explicitlyAllowed = self::hostMatchesList($host, $policy->allowedHostSuffixes());
        if ($policy->allowedHostSuffixes() !== [] && !$explicitlyAllowed) {
            return new UrlValidationResult(false, self::REASON_HOST_NOT_ALLOWED);
        }

        if ($policy->blockLocalHostnames() && self::hostMatchesList($host, $policy->blockedHostSuffixes()) && !$explicitlyAllowed) {
            return new UrlValidationResult(false, self::REASON_HOST_BLOCKED);
        }

        if (self::isIpLiteral($host)) {
            if (!$policy->allowIpLiteral()) {
                return new UrlValidationResult(false, self::REASON_IP_LITERAL_NOT_ALLOWED);
            }

            if (self::isBlockedIp($host, $policy->allowPrivateIps())) {
                return new UrlValidationResult(false, self::REASON_IP_BLOCKED);
            }

            return new UrlValidationResult(true);
        }

        $ips = self::resolveHostIps($host, $policy->resolver());
        if ($ips === []) {
            return new UrlValidationResult(false, self::REASON_DNS_LOOKUP_FAILED);
        }

        foreach ($ips as $ip) {
            if (!is_string($ip) || $ip === '') {
                return new UrlValidationResult(false, self::REASON_DNS_LOOKUP_FAILED);
            }
            if (self::isBlockedIp($ip, $policy->allowPrivateIps())) {
                return new UrlValidationResult(false, self::REASON_IP_BLOCKED);
            }
        }

        return new UrlValidationResult(true);
    }

    public static function assertSafeHttpUrl(string $url, UrlPolicy $policy): void
    {
        $result = self::validateHttpUrlWithPolicy($url, $policy);
        if (!$result->ok()) {
            throw new \RuntimeException($result->reason());
        }
    }

    /** @param array<int, string> $allowedSchemes */
    private static function isAllowedScheme(string $scheme, array $allowedSchemes): bool
    {
        foreach ($allowedSchemes as $allowed) {
            if ($scheme === strtolower((string) $allowed)) {
                return true;
            }
        }
        return false;
    }

    /** @param array<int, string> $suffixes */
    private static function hostMatchesList(string $host, array $suffixes): bool
    {
        if ($suffixes === []) {
            return false;
        }
        foreach ($suffixes as $suffix) {
            $suffix = strtolower((string) $suffix);
            if ($suffix === '') {
                continue;
            }
            if ($host === $suffix) {
                return true;
            }
            if (str_ends_with($host, '.' . $suffix)) {
                return true;
            }
        }
        return false;
    }

    /** @return array<int, string> */
    private static function resolveHostIps(string $host, ?callable $resolver): array
    {
        if ($resolver !== null) {
            $resolved = $resolver($host);
            return is_array($resolved) ? array_values(array_unique(array_filter($resolved))) : [];
        }

        $ips = [];
        if (function_exists('dns_get_record')) {
            $records = dns_get_record($host, DNS_A + DNS_AAAA);
            if (is_array($records)) {
                foreach ($records as $record) {
                    if (isset($record['ip']) && is_string($record['ip'])) {
                        $ips[] = $record['ip'];
                    }
                    if (isset($record['ipv6']) && is_string($record['ipv6'])) {
                        $ips[] = $record['ipv6'];
                    }
                }
            }
        } else {
            $v4 = gethostbynamel($host);
            if (is_array($v4)) {
                $ips = array_merge($ips, $v4);
            }
        }

        if ($ips === []) {
            $single = gethostbyname($host);
            if (is_string($single) && $single !== '' && $single !== $host) {
                $ips[] = $single;
            }
        }

        return array_values(array_unique($ips));
    }

    private static function isBlockedIp(string $ip, bool $allowPrivateIps): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return self::isBlockedIpv4($ip, $allowPrivateIps);
        }
        if (self::isIpv6($ip)) {
            return self::isBlockedIpv6($ip, $allowPrivateIps);
        }
        return true;
    }

    private static function isBlockedIpv4(string $ip, bool $allowPrivateIps): bool
    {
        $alwaysBlocked = [
            '0.0.0.0/8',
            '224.0.0.0/4',
            '240.0.0.0/4',
        ];
        foreach ($alwaysBlocked as $range) {
            if (self::ipv4InCidr($ip, $range)) {
                return true;
            }
        }

        if ($allowPrivateIps) {
            return false;
        }

        $privateRanges = [
            '10.0.0.0/8',
            '127.0.0.0/8',
            '169.254.0.0/16',
            '172.16.0.0/12',
            '192.168.0.0/16',
        ];
        foreach ($privateRanges as $range) {
            if (self::ipv4InCidr($ip, $range)) {
                return true;
            }
        }

        return false;
    }

    private static function ipv4InCidr(string $ip, string $cidr): bool
    {
        [$subnet, $prefix] = explode('/', $cidr, 2);
        $ipLong = ip2long($ip);
        $subnetLong = ip2long($subnet);
        if ($ipLong === false || $subnetLong === false) {
            return false;
        }
        $ipLong = (int) sprintf('%u', $ipLong);
        $subnetLong = (int) sprintf('%u', $subnetLong);
        $mask = $prefix === '0' ? 0 : ((~0 << (32 - (int) $prefix)) & 0xFFFFFFFF);
        return (($ipLong & $mask) === ($subnetLong & $mask));
    }

    private static function isBlockedIpv6(string $ip, bool $allowPrivateIps): bool
    {
        $mapped = self::ipv6MappedIpv4($ip);
        if ($mapped !== null) {
            return self::isBlockedIpv4($mapped, $allowPrivateIps);
        }

        $alwaysBlocked = [
            '::/128',
            'ff00::/8',
        ];
        foreach ($alwaysBlocked as $range) {
            if (self::ipv6InCidr($ip, $range)) {
                return true;
            }
        }

        if ($allowPrivateIps) {
            return false;
        }

        $privateRanges = [
            '::1/128',
            'fe80::/10',
            'fc00::/7',
        ];
        foreach ($privateRanges as $range) {
            if (self::ipv6InCidr($ip, $range)) {
                return true;
            }
        }

        return false;
    }

    private static function ipv6InCidr(string $ip, string $cidr): bool
    {
        [$subnet, $prefix] = explode('/', $cidr, 2);
        $ipBin = inet_pton($ip);
        $subnetBin = inet_pton($subnet);
        if ($ipBin === false || $subnetBin === false) {
            return false;
        }

        $prefix = (int) $prefix;
        $bytes = intdiv($prefix, 8);
        $bits = $prefix % 8;

        if ($bytes > 0 && substr($ipBin, 0, $bytes) !== substr($subnetBin, 0, $bytes)) {
            return false;
        }
        if ($bits === 0) {
            return true;
        }

        $mask = chr((0xFF << (8 - $bits)) & 0xFF);
        return (ord($ipBin[$bytes]) & ord($mask)) === (ord($subnetBin[$bytes]) & ord($mask));
    }

    private static function ipv6MappedIpv4(string $ip): ?string
    {
        if (!str_contains($ip, '::ffff:')) {
            return null;
        }

        $packed = inet_pton($ip);
        if ($packed === false || strlen($packed) !== 16) {
            return null;
        }

        $prefix = substr($packed, 0, 12);
        if ($prefix !== str_repeat("\x00", 10) . "\xFF\xFF") {
            return null;
        }

        $v4 = substr($packed, 12, 4);
        $ipV4 = inet_ntop($v4);
        return $ipV4 === false ? null : $ipV4;
    }

    private static function isIpLiteral(string $host): bool
    {
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return true;
        }
        if (!str_contains($host, ':')) {
            return false;
        }
        return inet_pton($host) !== false;
    }

    private static function isIpv6(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            return true;
        }
        if (!str_contains($ip, ':')) {
            return false;
        }
        return inet_pton($ip) !== false;
    }
}
