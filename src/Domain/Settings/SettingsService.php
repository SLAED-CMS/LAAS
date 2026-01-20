<?php
declare(strict_types=1);

namespace Laas\Domain\Settings;

use Laas\Database\DatabaseManager;
use Laas\Database\Repositories\SettingsRepository;
use RuntimeException;
use Throwable;

class SettingsService implements SettingsServiceInterface
{
    private const DEFAULT_SITE_NAME = 'LAAS CMS';
    private const DEFAULT_LOCALE = 'en';
    private const DEFAULT_THEME = 'default';
    private const DEFAULT_TOKEN_ISSUE_MODE = 'admin';

    private ?SettingsRepository $repository = null;

    public function __construct(private DatabaseManager $db)
    {
    }

    /** @return array<int, array{key: string, value: mixed, source: string, type: string}> */
    public function list(): array
    {
        $payload = $this->settingsWithSources();
        $items = [];
        foreach ($payload['settings'] as $key => $value) {
            $items[] = $this->normalizeSetting([
                'key' => $key,
                'value' => $value,
                'source' => $payload['sources'][$key] ?? 'CONFIG',
                'type' => $this->typeForKey($key),
            ]);
        }

        return $items;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $defaults = $this->defaultSettings();
        $defaultValue = array_key_exists($key, $defaults) ? $defaults[$key] : $default;

        $repo = $this->tryRepository();
        if ($repo === null) {
            return $defaultValue;
        }

        return $repo->get($this->storageKey($key), $defaultValue);
    }

    public function has(string $key): bool
    {
        $repo = $this->tryRepository();
        if ($repo === null) {
            return false;
        }

        return $repo->has($this->storageKey($key));
    }

    /** @mutation */
    public function set(string $key, mixed $value): void
    {
        $repo = $this->repository();
        $repo->set($this->storageKey($key), $value, $this->typeForKey($key));
    }

    /** @mutation */
    public function setMany(array $pairs): void
    {
        $repo = $this->repository();
        foreach ($pairs as $key => $value) {
            if (!is_string($key) || $key === '') {
                continue;
            }
            $repo->set($this->storageKey($key), $value, $this->typeForKey($key));
        }
    }

    /** @return array{settings: array<string, mixed>, sources: array<string, string>} */
    public function settingsWithSources(): array
    {
        $defaults = $this->defaultSettings();
        $settings = $defaults;
        $sources = [];

        foreach (array_keys($defaults) as $key) {
            $sources[$key] = 'CONFIG';
        }

        $repo = $this->tryRepository();
        if ($repo === null) {
            return [
                'settings' => $settings,
                'sources' => $sources,
            ];
        }

        foreach ($defaults as $key => $defaultValue) {
            $settings[$key] = $repo->get($this->storageKey($key), $defaultValue);
            $sources[$key] = $repo->has($this->storageKey($key)) ? 'DB' : 'CONFIG';
        }

        return [
            'settings' => $settings,
            'sources' => $sources,
        ];
    }

    /** @return array<string, string> */
    public function sources(array $keys): array
    {
        $sources = [];
        $repo = $this->tryRepository();
        foreach ($keys as $key) {
            if (!is_string($key) || $key === '') {
                continue;
            }
            $sources[$key] = $repo !== null && $repo->has($this->storageKey($key)) ? 'DB' : 'CONFIG';
        }

        return $sources;
    }

    /** @param array<string, mixed> $settingRow */
    public function normalizeSetting(array $settingRow): array
    {
        return [
            'key' => (string) ($settingRow['key'] ?? ''),
            'value' => $settingRow['value'] ?? null,
            'source' => (string) ($settingRow['source'] ?? 'CONFIG'),
            'type' => (string) ($settingRow['type'] ?? 'string'),
        ];
    }

    /** @return array<string, mixed> */
    public function defaultSettings(): array
    {
        $appConfig = $this->loadConfig('app.php');
        $apiConfig = $this->loadConfig('api.php');

        return [
            'site_name' => self::DEFAULT_SITE_NAME,
            'default_locale' => (string) ($appConfig['default_locale'] ?? self::DEFAULT_LOCALE),
            'theme' => (string) ($appConfig['theme'] ?? self::DEFAULT_THEME),
            'api_token_issue_mode' => (string) ($apiConfig['token_issue_mode'] ?? self::DEFAULT_TOKEN_ISSUE_MODE),
        ];
    }

    /** @return array<int, string> */
    public function availableLocales(): array
    {
        $appConfig = $this->loadConfig('app.php');
        $values = $appConfig['locales'] ?? [];

        $out = [];
        foreach ($values as $value) {
            if (is_string($value)) {
                $out[] = $value;
            }
        }

        return $out;
    }

    /** @return array<int, string> */
    public function availableThemes(): array
    {
        $themesDir = $this->rootPath() . '/themes';
        $themesDir = realpath($themesDir) ?: $themesDir;
        if (!is_dir($themesDir)) {
            return [];
        }

        $items = scandir($themesDir) ?: [];
        $themes = [];
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $themesDir . '/' . $item;
            if (!is_dir($path)) {
                continue;
            }

            $hasLayout = is_file($path . '/layout.html');
            $hasMeta = is_file($path . '/theme.json');
            if (!$hasLayout && !$hasMeta) {
                continue;
            }

            $themes[] = $item;
        }

        sort($themes);
        return $themes;
    }

    private function repository(): SettingsRepository
    {
        if ($this->repository !== null) {
            return $this->repository;
        }

        if (!$this->db->healthCheck()) {
            throw new RuntimeException('Database unavailable.');
        }

        try {
            $this->repository = new SettingsRepository($this->db->pdo());
        } catch (Throwable $e) {
            throw new RuntimeException('Database unavailable.', 0, $e);
        }

        return $this->repository;
    }

    private function tryRepository(): ?SettingsRepository
    {
        try {
            return $this->repository();
        } catch (Throwable) {
            return null;
        }
    }

    private function storageKey(string $key): string
    {
        return match ($key) {
            'api_token_issue_mode' => 'api.token_issue_mode',
            default => $key,
        };
    }

    private function typeForKey(string $key): string
    {
        if (str_starts_with($key, 'changelog.')) {
            return match ($key) {
                'changelog.enabled', 'changelog.show_merges' => 'bool',
                'changelog.cache_ttl_seconds', 'changelog.per_page' => 'int',
                default => 'string',
            };
        }

        return match ($key) {
            'site_name', 'default_locale', 'theme', 'api_token_issue_mode' => 'string',
            default => 'string',
        };
    }

    private function loadConfig(string $file): array
    {
        $configPath = $this->rootPath() . '/config/' . $file;
        $configPath = realpath($configPath) ?: $configPath;
        if (!is_file($configPath)) {
            return [];
        }

        $config = require $configPath;
        return is_array($config) ? $config : [];
    }

    private function rootPath(): string
    {
        return dirname(__DIR__, 3);
    }
}
