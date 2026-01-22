<?php

declare(strict_types=1);

namespace Laas\Modules\Changelog\Support;

final class ChangelogValidator
{
    /** @return array{values: array<string, mixed>, errors: array<int, string>} */
    public function validate(array $input, string $rootPath, bool $dbTokenAllowed): array
    {
        $errors = [];
        $values = [];

        $values['enabled'] = $this->toBool($input['enabled'] ?? false);
        $values['show_merges'] = $this->toBool($input['show_merges'] ?? false);

        $values['source_type'] = $this->normalizeSource((string) ($input['source_type'] ?? 'github'));
        if (!in_array($values['source_type'], ['github', 'git'], true)) {
            $errors[] = 'changelog.admin.validation_failed';
            $values['source_type'] = 'github';
        }

        $values['cache_ttl_seconds'] = $this->clampInt($input['cache_ttl_seconds'] ?? 300, 30, 3600, 300);
        $values['per_page'] = $this->clampInt($input['per_page'] ?? 20, 1, 50, 20);

        $values['branch'] = $this->sanitizeBranch((string) ($input['branch'] ?? 'main'));
        if ($values['branch'] === '') {
            $values['branch'] = 'main';
        }

        $values['github_owner'] = trim((string) ($input['github_owner'] ?? ''));
        $values['github_repo'] = trim((string) ($input['github_repo'] ?? ''));
        if (($values['source_type'] === 'github') && (!$this->isSafeRepoPart($values['github_owner']) || !$this->isSafeRepoPart($values['github_repo']))) {
            $errors[] = 'changelog.admin.validation_failed';
        }

        $values['github_token_mode'] = (string) ($input['github_token_mode'] ?? 'env');
        if ($values['github_token_mode'] !== 'env') {
            if (!$dbTokenAllowed) {
                $errors[] = 'changelog.admin.token_db_disabled';
                $values['github_token_mode'] = 'env';
            } else {
                $values['github_token_mode'] = 'db';
            }
        }
        $values['github_token_env_key'] = $this->sanitizeEnvKey((string) ($input['github_token_env_key'] ?? 'GITHUB_TOKEN'));
        $values['github_token_db'] = trim((string) ($input['github_token_db'] ?? ''));

        $gitRepoPath = trim((string) ($input['git_repo_path'] ?? ''));
        $values['git_repo_path'] = $gitRepoPath !== '' ? $gitRepoPath : $rootPath;
        if ($values['source_type'] === 'git' && !$this->isSafeRepoPath($values['git_repo_path'], $rootPath)) {
            $errors[] = 'changelog.admin.invalid_repo_path';
        }

        $gitBinaryPath = trim((string) ($input['git_binary_path'] ?? 'git'));
        $values['git_binary_path'] = $gitBinaryPath !== '' ? $gitBinaryPath : 'git';

        return [
            'values' => $values,
            'errors' => array_values(array_unique($errors)),
        ];
    }

    private function toBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_string($value)) {
            return in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true);
        }
        if (is_int($value)) {
            return $value === 1;
        }
        return false;
    }

    private function clampInt(mixed $value, int $min, int $max, int $fallback): int
    {
        if (!is_numeric($value)) {
            return $fallback;
        }
        $int = (int) $value;
        if ($int < $min) {
            return $min;
        }
        if ($int > $max) {
            return $max;
        }
        return $int;
    }

    private function normalizeSource(string $value): string
    {
        $value = strtolower(trim($value));
        return in_array($value, ['github', 'git'], true) ? $value : 'github';
    }

    private function sanitizeBranch(string $branch): string
    {
        $branch = trim($branch);
        if ($branch === '') {
            return '';
        }
        if (!preg_match('/^[A-Za-z0-9._\\/-]+$/', $branch)) {
            return '';
        }
        return $branch;
    }

    private function isSafeRepoPart(string $value): bool
    {
        return $value !== '' && (bool) preg_match('/^[A-Za-z0-9._-]+$/', $value);
    }

    private function sanitizeEnvKey(string $value): string
    {
        $value = strtoupper(trim($value));
        if ($value === '' || !preg_match('/^[A-Z0-9_]+$/', $value)) {
            return 'GITHUB_TOKEN';
        }
        return $value;
    }

    private function isSafeRepoPath(string $path, string $rootPath): bool
    {
        $realPath = realpath($path);
        $realRoot = realpath($rootPath);
        if ($realPath === false || $realRoot === false) {
            return false;
        }

        $realPath = rtrim(str_replace('\\', '/', $realPath), '/');
        $realRoot = rtrim(str_replace('\\', '/', $realRoot), '/');

        if (!str_starts_with(strtolower($realPath), strtolower($realRoot))) {
            return false;
        }

        return is_dir($realPath);
    }
}
