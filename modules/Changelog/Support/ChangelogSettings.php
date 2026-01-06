<?php
declare(strict_types=1);

namespace Laas\Modules\Changelog\Support;

use Laas\Database\Repositories\SettingsRepository;

final class ChangelogSettings
{
    /** @return array<string, mixed> */
    public static function defaults(string $rootPath): array
    {
        return [
            'enabled' => false,
            'source_type' => 'github',
            'cache_ttl_seconds' => 300,
            'per_page' => 20,
            'show_merges' => false,
            'branch' => 'main',
            'github_owner' => '',
            'github_repo' => '',
            'github_token_mode' => 'env',
            'github_token_env_key' => 'GITHUB_TOKEN',
            'github_token_db' => '',
            'git_repo_path' => $rootPath,
            'git_binary_path' => 'git',
        ];
    }

    /** @return array<int, string> */
    public static function keys(): array
    {
        return [
            'changelog.enabled',
            'changelog.source_type',
            'changelog.cache_ttl_seconds',
            'changelog.per_page',
            'changelog.show_merges',
            'changelog.branch',
            'changelog.github_owner',
            'changelog.github_repo',
            'changelog.github_token_mode',
            'changelog.github_token_env_key',
            'changelog.github_token_db',
            'changelog.git_repo_path',
            'changelog.git_binary_path',
        ];
    }

    /** @return array<string, mixed> */
    public static function load(string $rootPath, ?SettingsRepository $repo): array
    {
        $values = self::defaults($rootPath);
        if ($repo === null) {
            return $values;
        }

        $map = [
            'changelog.enabled' => 'enabled',
            'changelog.source_type' => 'source_type',
            'changelog.cache_ttl_seconds' => 'cache_ttl_seconds',
            'changelog.per_page' => 'per_page',
            'changelog.show_merges' => 'show_merges',
            'changelog.branch' => 'branch',
            'changelog.github_owner' => 'github_owner',
            'changelog.github_repo' => 'github_repo',
            'changelog.github_token_mode' => 'github_token_mode',
            'changelog.github_token_env_key' => 'github_token_env_key',
            'changelog.github_token_db' => 'github_token_db',
            'changelog.git_repo_path' => 'git_repo_path',
            'changelog.git_binary_path' => 'git_binary_path',
        ];

        foreach ($map as $key => $target) {
            $has = $repo->has($key);
            if ($has) {
                $val = $repo->get($key, $values[$target] ?? null);
                $values[$target] = $val;
            }
        }

        return $values;
    }
}
