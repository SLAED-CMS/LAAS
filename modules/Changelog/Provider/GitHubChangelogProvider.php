<?php
declare(strict_types=1);

namespace Laas\Modules\Changelog\Provider;

use Laas\Modules\Changelog\Dto\ChangelogCommit;
use Laas\Modules\Changelog\Dto\ChangelogPage;
use Laas\Modules\Changelog\Dto\ProviderTestResult;
use Laas\Support\RequestScope;
use RuntimeException;

final class GitHubChangelogProvider implements ChangelogProviderInterface
{
    private string $userAgent;
    private int $timeout;
    /** @var callable */
    private $resolver;

    /** @var callable */
    private $httpClient;

    public function __construct(
        private string $owner,
        private string $repo,
        private ?string $token = null,
        ?callable $httpClient = null,
        int $timeout = 8,
        string $userAgent = 'LAAS-CMS',
        ?callable $resolver = null
    ) {
        $this->httpClient = $httpClient ?? [$this, 'defaultHttpClient'];
        $this->timeout = $timeout;
        $this->userAgent = $userAgent;
        $this->resolver = $resolver ?? [$this, 'defaultResolver'];
    }

    public function fetchCommits(string $branch, int $limit, int $page, bool $includeMerges, array $filters = []): ChangelogPage
    {
        $url = $this->buildUrl($branch, $limit, $page, $filters);
        $headers = $this->buildHeaders();
        $this->assertSafeUrl($url);

        $response = ($this->httpClient)($url, $headers, $this->timeout);
        $status = (int) ($response['status'] ?? 0);
        $body = (string) ($response['body'] ?? '');
        $headersOut = is_array($response['headers'] ?? null) ? $response['headers'] : [];

        if ($status < 200 || $status >= 300) {
            throw new RuntimeException('GitHub API error');
        }

        $payload = json_decode($body, true);
        if (!is_array($payload)) {
            throw new RuntimeException('GitHub response invalid');
        }

        $commits = [];
        $search = trim((string) ($filters['search'] ?? ''));
        foreach ($payload as $row) {
            if (!is_array($row)) {
                continue;
            }
            $parents = isset($row['parents']) && is_array($row['parents']) ? $row['parents'] : [];
            if (!$includeMerges && count($parents) > 1) {
                continue;
            }
            $commit = $this->mapCommit($row);
            if ($commit !== null) {
                if ($search !== '' && stripos($commit->title . "\n" . $commit->body, $search) === false) {
                    continue;
                }
                $commits[] = $commit;
            }
        }

        $hasMore = $this->hasNextPage($headersOut);

        return new ChangelogPage($commits, $page, $limit, $hasMore);
    }

    public function testConnection(): ProviderTestResult
    {
        try {
            $url = $this->buildUrl('main', 1, 1, []);
            $headers = $this->buildHeaders();
            $this->assertSafeUrl($url);
            $response = ($this->httpClient)($url, $headers, $this->timeout);
            $status = (int) ($response['status'] ?? 0);
            if ($status >= 200 && $status < 300) {
                return new ProviderTestResult(true, 'OK');
            }
        } catch (RuntimeException) {
            // fallthrough
        }

        return new ProviderTestResult(false, 'GitHub API error');
    }

    /** @param array<string, string> $filters */
    private function buildUrl(string $branch, int $limit, int $page, array $filters): string
    {
        $queryParams = [
            'sha' => $branch,
            'per_page' => $limit,
            'page' => $page,
        ];
        $author = trim((string) ($filters['author'] ?? ''));
        $since = trim((string) ($filters['date_from'] ?? ''));
        $until = trim((string) ($filters['date_to'] ?? ''));
        $path = trim((string) ($filters['file'] ?? ''));
        if ($author !== '') {
            $queryParams['author'] = $author;
        }
        if ($since !== '') {
            $queryParams['since'] = $since . 'T00:00:00Z';
        }
        if ($until !== '') {
            $queryParams['until'] = $until . 'T23:59:59Z';
        }
        if ($path !== '') {
            $queryParams['path'] = $path;
        }
        $query = http_build_query($queryParams);
        return 'https://api.github.com/repos/' . rawurlencode($this->owner) . '/' . rawurlencode($this->repo) . '/commits?' . $query;
    }

    /** @return array<int, string> */
    private function buildHeaders(): array
    {
        $headers = [
            'Accept: application/vnd.github+json',
            'User-Agent: ' . $this->userAgent,
        ];
        if ($this->token !== null && $this->token !== '') {
            $headers[] = 'Authorization: Bearer ' . $this->token;
        }
        return $headers;
    }

    /** @param array<string, mixed> $row */
    private function mapCommit(array $row): ?ChangelogCommit
    {
        $sha = (string) ($row['sha'] ?? '');
        if ($sha === '') {
            return null;
        }
        $short = substr($sha, 0, 7);
        $commit = is_array($row['commit'] ?? null) ? $row['commit'] : [];
        $message = (string) ($commit['message'] ?? '');
        [$title, $body] = $this->splitMessage($message);
        $author = is_array($commit['author'] ?? null) ? $commit['author'] : [];

        return new ChangelogCommit(
            $sha,
            $short,
            $title,
            $body,
            (string) ($author['name'] ?? 'unknown'),
            isset($author['email']) ? (string) $author['email'] : null,
            (string) ($author['date'] ?? ''),
            isset($row['html_url']) ? (string) $row['html_url'] : null
        );
    }

    /** @return array{0: string, 1: string} */
    private function splitMessage(string $message): array
    {
        $parts = preg_split('/\r?\n/', $message, 2) ?: [];
        $title = isset($parts[0]) ? trim($parts[0]) : '';
        $body = isset($parts[1]) ? trim($parts[1]) : '';
        return [$title, $body];
    }

    /** @param array<string, string> $headers */
    private function hasNextPage(array $headers): bool
    {
        $link = $headers['link'] ?? $headers['Link'] ?? '';
        if (!is_string($link) || $link === '') {
            return false;
        }
        return str_contains($link, 'rel="next"');
    }

    /** @return array{status: int, body: string, headers: array<string, string>} */
    private function defaultHttpClient(string $url, array $headers, int $timeout): array
    {
        $this->assertSafeUrl($url);
        $started = microtime(true);
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HEADER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, min(5, $timeout));
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
        curl_setopt($ch, CURLOPT_MAXREDIRS, 0);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        if (defined('CURLOPT_PROTOCOLS')) {
            curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTPS);
        }
        if (defined('CURLOPT_REDIR_PROTOCOLS')) {
            curl_setopt($ch, CURLOPT_REDIR_PROTOCOLS, CURLPROTO_HTTPS);
        }

        $raw = curl_exec($ch);
        if ($raw === false) {
            curl_close($ch);
            throw new RuntimeException('GitHub request failed');
        }

        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);
        $durationMs = (microtime(true) - $started) * 1000;
        $context = RequestScope::get('devtools.context');
        if ($context instanceof \Laas\DevTools\DevToolsContext) {
            $context->addExternalCall('GET', $url, $status, $durationMs);
        }

        $headerRaw = substr($raw, 0, $headerSize);
        $body = substr($raw, $headerSize);
        $parsedHeaders = $this->parseHeaders($headerRaw);

        return [
            'status' => $status,
            'body' => $body,
            'headers' => $parsedHeaders,
        ];
    }

    /** @return array<string, string> */
    private function parseHeaders(string $raw): array
    {
        $headers = [];
        $lines = preg_split("/\r?\n/", $raw) ?: [];
        foreach ($lines as $line) {
            if (strpos($line, ':') === false) {
                continue;
            }
            [$name, $value] = explode(':', $line, 2);
            $headers[trim($name)] = trim($value);
        }
        return $headers;
    }

    private function assertSafeUrl(string $url): void
    {
        $parts = parse_url($url);
        if (!is_array($parts)) {
            throw new RuntimeException('GitHub URL not allowed');
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));
        if ($scheme !== 'https' || $host === '' || isset($parts['user']) || isset($parts['pass'])) {
            throw new RuntimeException('GitHub URL not allowed');
        }

        $allowedHosts = [
            'api.github.com' => true,
            'github.com' => true,
        ];
        if (!isset($allowedHosts[$host])) {
            throw new RuntimeException('GitHub URL not allowed');
        }

        $ips = ($this->resolver)($host);
        if (!is_array($ips) || $ips === []) {
            throw new RuntimeException('GitHub URL not allowed');
        }
        foreach ($ips as $ip) {
            if (!is_string($ip) || $ip === '') {
                continue;
            }
            if ($this->isBlockedIp($ip)) {
                throw new RuntimeException('GitHub URL not allowed');
            }
        }
    }

    /** @return array<int, string> */
    private function defaultResolver(string $host): array
    {
        $ips = [];
        $v4 = gethostbynamel($host);
        if (is_array($v4)) {
            $ips = array_merge($ips, $v4);
        }
        if (function_exists('dns_get_record')) {
            $records = dns_get_record($host, DNS_AAAA);
            if (is_array($records)) {
                foreach ($records as $record) {
                    if (isset($record['ipv6']) && is_string($record['ipv6'])) {
                        $ips[] = $record['ipv6'];
                    }
                }
            }
        }

        $ips = array_values(array_unique($ips));
        return $ips;
    }

    private function isBlockedIp(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return $this->isBlockedIpv4($ip);
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            return $this->isBlockedIpv6($ip);
        }
        return true;
    }

    private function isBlockedIpv4(string $ip): bool
    {
        $ranges = [
            '10.0.0.0/8',
            '127.0.0.0/8',
            '169.254.0.0/16',
            '172.16.0.0/12',
            '192.168.0.0/16',
        ];
        foreach ($ranges as $range) {
            if ($this->ipv4InCidr($ip, $range)) {
                return true;
            }
        }
        return false;
    }

    private function ipv4InCidr(string $ip, string $cidr): bool
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

    private function isBlockedIpv6(string $ip): bool
    {
        $ranges = [
            '::1/128',
            'fe80::/10',
            'fc00::/7',
        ];
        foreach ($ranges as $range) {
            if ($this->ipv6InCidr($ip, $range)) {
                return true;
            }
        }
        return false;
    }

    private function ipv6InCidr(string $ip, string $cidr): bool
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
}
