<?php
declare(strict_types=1);

namespace Laas\Modules\Changelog\Service;

use Laas\Modules\Changelog\Dto\ChangelogPage;
use Laas\Modules\Changelog\Provider\ChangelogProviderInterface;
use Laas\Modules\Changelog\Provider\GitHubChangelogProvider;
use Laas\Modules\Changelog\Provider\LocalGitChangelogProvider;
use Laas\Modules\Changelog\Support\ChangelogCache;
use RuntimeException;

final class ChangelogService
{
    public function __construct(
        private string $rootPath,
        private ChangelogCache $cache
    ) {
    }

    /** @param array<string, string> $filters */
    public function fetchPage(array $settings, int $page, bool $includeMerges, array $filters = []): ChangelogPage
    {
        $source = (string) ($settings['source_type'] ?? 'github');
        $branch = (string) ($settings['branch'] ?? 'main');
        $perPage = (int) ($settings['per_page'] ?? 20);
        $perPage = max(1, min(50, $perPage));
        $ttl = (int) ($settings['cache_ttl_seconds'] ?? 300);
        $ttl = max(30, min(3600, $ttl));

        $filterKey = $filters !== [] ? ':f:' . sha1(json_encode($filters, JSON_UNESCAPED_SLASHES)) : '';
        $key = $this->cache->buildKey($source, $branch, $page, $perPage, $includeMerges) . $filterKey;
        $cached = $this->cache->get($key);
        if ($cached instanceof ChangelogPage) {
            return $cached;
        }

        $lock = $this->cache->acquireLock($key, 2);
        if ($lock !== null) {
            try {
                $cached = $this->cache->get($key);
                if ($cached instanceof ChangelogPage) {
                    return $cached;
                }
                $provider = $this->buildProvider($settings);
                $pageData = $provider->fetchCommits($branch, $perPage, $page, $includeMerges, $filters);
                $this->cache->set($key, $pageData, $ttl);
                return $pageData;
            } finally {
                $this->cache->releaseLock($lock);
            }
        }

        $stale = $this->cache->get($key, true);
        if ($stale instanceof ChangelogPage) {
            return $stale;
        }

        $provider = $this->buildProvider($settings);
        $pageData = $provider->fetchCommits($branch, $perPage, $page, $includeMerges, $filters);
        $this->cache->set($key, $pageData, $ttl);
        return $pageData;
    }

    public function buildProvider(array $settings): ChangelogProviderInterface
    {
        $source = (string) ($settings['source_type'] ?? 'github');
        if ($source === 'git') {
            $path = (string) ($settings['git_repo_path'] ?? $this->rootPath);
            $gitBinary = (string) ($settings['git_binary_path'] ?? 'git');
            return new LocalGitChangelogProvider($path, $gitBinary);
        }

        $owner = (string) ($settings['github_owner'] ?? '');
        $repo = (string) ($settings['github_repo'] ?? '');
        $token = null;
        if (($settings['github_token_mode'] ?? 'env') === 'env') {
            $envKey = (string) ($settings['github_token_env_key'] ?? 'GITHUB_TOKEN');
            $token = getenv($envKey) ?: null;
        }

        if ($owner === '' || $repo === '') {
            throw new RuntimeException('GitHub settings missing');
        }

        return new GitHubChangelogProvider($owner, $repo, $token);
    }
}
