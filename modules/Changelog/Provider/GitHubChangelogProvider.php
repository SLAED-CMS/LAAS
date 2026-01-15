<?php
declare(strict_types=1);

namespace Laas\Modules\Changelog\Provider;

use Laas\Modules\Changelog\Dto\ChangelogCommit;
use Laas\Modules\Changelog\Dto\ChangelogPage;
use Laas\Modules\Changelog\Dto\ProviderTestResult;
use Laas\Support\SafeHttpClient;
use Laas\Support\UrlPolicy;
use Laas\Support\UrlValidator;
use RuntimeException;

final class GitHubChangelogProvider implements ChangelogProviderInterface
{
    private string $userAgent;
    private int $timeout;
    /** @var null|callable */
    private $resolver;

    /** @var callable */
    private $httpClient;

    private ?SafeHttpClient $safeClient = null;
    private ?UrlPolicy $githubPolicy = null;
    /** @var null|array<string, mixed> */
    private ?array $httpConfig = null;

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
        $this->resolver = $resolver;
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
        $client = $this->safeClient();
        return $client->request('GET', $url, $headers, null, [
            'timeout' => $timeout,
            'connect_timeout' => min(5, $timeout),
            'max_redirects' => 0,
        ]);
    }

    private function assertSafeUrl(string $url): void
    {
        $policy = $this->githubPolicy();
        try {
            UrlValidator::assertSafeHttpUrl($url, $policy);
        } catch (RuntimeException) {
            throw new RuntimeException('GitHub URL not allowed');
        }
    }

    private function safeClient(): SafeHttpClient
    {
        if ($this->safeClient !== null) {
            return $this->safeClient;
        }

        $this->safeClient = SafeHttpClient::fromConfig($this->httpConfig(), $this->githubPolicy());
        return $this->safeClient;
    }

    private function githubPolicy(): UrlPolicy
    {
        if ($this->githubPolicy !== null) {
            return $this->githubPolicy;
        }

        $config = $this->httpConfig();
        $ssrf = is_array($config['ssrf'] ?? null) ? $config['ssrf'] : [];
        $allowedHosts = is_array($ssrf['allowed_github_hosts'] ?? null)
            ? $ssrf['allowed_github_hosts']
            : ['api.github.com', 'github.com'];
        if ($allowedHosts === []) {
            $allowedHosts = ['api.github.com', 'github.com'];
        }
        $blockedHosts = is_array($ssrf['blocked_host_suffixes'] ?? null)
            ? $ssrf['blocked_host_suffixes']
            : ['localhost', '.local', '.internal'];

        $this->githubPolicy = new UrlPolicy(
            allowedSchemes: ['https'],
            allowedHostSuffixes: $allowedHosts,
            allowPrivateIps: false,
            allowIpLiteral: false,
            blockLocalHostnames: true,
            blockedHostSuffixes: $blockedHosts,
            resolver: $this->resolver
        );

        return $this->githubPolicy;
    }

    /** @return array<string, mixed> */
    private function httpConfig(): array
    {
        if ($this->httpConfig !== null) {
            return $this->httpConfig;
        }

        $path = dirname(__DIR__, 3) . '/config/http.php';
        if (!is_file($path)) {
            $this->httpConfig = [];
            return $this->httpConfig;
        }

        $config = require $path;
        $this->httpConfig = is_array($config) ? $config : [];
        return $this->httpConfig;
    }
}
