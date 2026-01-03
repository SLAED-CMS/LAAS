<?php
declare(strict_types=1);

namespace Laas\Support;

final class ConfigExporter
{
    public function __construct(
        private string $rootPath,
        private array $appConfig,
        private array $mediaConfig,
        private array $storageConfig,
        private array $modulesConfig,
        private array $settings,
        private ?string $schemaVersion = null
    ) {
        $this->rootPath = rtrim($this->rootPath, '/\\');
    }

    /** @return array{metadata: array<string, mixed>, config: array<string, mixed>, warnings: array<int, string>} */
    public function buildSnapshot(bool $redact, array $warnings = []): array
    {
        $appEnv = (string) ($this->appConfig['env'] ?? '');
        $settingsTheme = (string) ($this->settings['theme'] ?? '');
        $settingsLocale = (string) ($this->settings['default_locale'] ?? '');

        $snapshot = [
            'metadata' => [
                'generated_at' => (new \DateTimeImmutable('now'))->format(\DateTimeImmutable::ATOM),
                'app_version' => (string) ($this->appConfig['version'] ?? ''),
            ],
            'config' => [
                'app' => [
                    'env' => $appEnv,
                    'debug' => (bool) ($this->appConfig['debug'] ?? false),
                    'read_only' => (bool) ($this->appConfig['read_only'] ?? false),
                    'theme' => $settingsTheme !== '' ? $settingsTheme : (string) ($this->appConfig['theme'] ?? ''),
                    'admin_theme' => 'admin',
                    'locale' => $settingsLocale !== '' ? $settingsLocale : (string) ($this->appConfig['default_locale'] ?? ''),
                ],
                'storage' => $this->storageInfo(),
                'media' => $this->mediaInfo(),
                'security' => [
                    'read_only' => (bool) ($this->appConfig['read_only'] ?? false),
                    'devtools_enabled' => (bool) (($this->appConfig['devtools']['enabled'] ?? false)),
                    'devtools_effective' => $this->effectiveDevtoolsEnabled(),
                ],
                'modules' => $this->modulesInfo(),
                'settings' => $this->settings,
                'versions' => [
                    'app' => (string) ($this->appConfig['version'] ?? ''),
                    'schema' => $this->schemaVersion,
                ],
            ],
            'warnings' => $warnings,
        ];

        if ($snapshot['config']['versions']['schema'] === null) {
            $snapshot['warnings'][] = 'schema_version_unavailable';
        }

        return $redact ? $this->redact($snapshot) : $snapshot;
    }

    public function toJson(array $snapshot, bool $pretty): string
    {
        $flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
        if ($pretty) {
            $flags |= JSON_PRETTY_PRINT;
        }
        $json = json_encode($snapshot, $flags);
        if ($json === false) {
            return '';
        }
        return $json . "\n";
    }

    public function writeAtomic(string $path, string $contents): bool
    {
        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            return false;
        }

        $tmp = $path . '.tmp_' . bin2hex(random_bytes(6));
        if (file_put_contents($tmp, $contents, LOCK_EX) === false) {
            return false;
        }

        if (!rename($tmp, $path)) {
            @unlink($tmp);
            return false;
        }

        return true;
    }

    /** @return array<string, mixed> */
    private function storageInfo(): array
    {
        $default = (string) ($this->storageConfig['default'] ?? '');
        $s3 = $this->storageConfig['disks']['s3'] ?? [];
        if (!is_array($s3)) {
            $s3 = [];
        }

        return [
            'disk' => $default,
            'endpoint' => (string) ($s3['endpoint'] ?? ''),
            'region' => (string) ($s3['region'] ?? ''),
            'bucket' => (string) ($s3['bucket'] ?? ''),
            'prefix' => (string) ($s3['prefix'] ?? ''),
            'verify_tls' => (bool) ($s3['verify_tls'] ?? true),
            'use_path_style' => (bool) ($s3['use_path_style'] ?? false),
        ];
    }

    /** @return array<string, mixed> */
    private function mediaInfo(): array
    {
        return [
            'public_mode' => (string) ($this->mediaConfig['public_mode'] ?? ''),
            'signed_urls_enabled' => (bool) ($this->mediaConfig['signed_urls_enabled'] ?? false),
            'signed_url_ttl' => (int) ($this->mediaConfig['signed_url_ttl'] ?? 0),
            'max_bytes' => (int) ($this->mediaConfig['max_bytes'] ?? 0),
            'thumb_format' => (string) ($this->mediaConfig['thumb_format'] ?? ''),
            'thumb_quality' => (int) ($this->mediaConfig['thumb_quality'] ?? 0),
            'thumb_algo_version' => (int) ($this->mediaConfig['thumb_algo_version'] ?? 0),
            'thumb_variants' => $this->mediaConfig['thumb_variants'] ?? [],
        ];
    }

    /** @return array<int, string> */
    private function modulesInfo(): array
    {
        $list = [];
        foreach ($this->modulesConfig as $module) {
            if (!is_string($module) || $module === '') {
                continue;
            }
            $parts = explode('\\', trim($module, '\\'));
            $name = end($parts);
            if ($name === false) {
                continue;
            }
            $name = preg_replace('/Module$/', '', $name);
            if ($name === '') {
                continue;
            }
            $list[] = strtolower($name);
        }

        sort($list);
        return $list;
    }

    private function effectiveDevtoolsEnabled(): bool
    {
        $env = strtolower((string) ($this->appConfig['env'] ?? ''));
        if ($env === 'prod') {
            return false;
        }

        return (bool) ($this->appConfig['debug'] ?? false)
            && (bool) (($this->appConfig['devtools']['enabled'] ?? false));
    }

    /** @return array<string, mixed> */
    private function redact(array $data): array
    {
        $result = [];
        foreach ($data as $key => $value) {
            $keyString = is_string($key) ? $key : '';
            if ($keyString !== '' && preg_match('/(secret|password|token|key)/i', $keyString)) {
                $result[$key] = '[redacted]';
                continue;
            }
            if (is_array($value)) {
                $result[$key] = $this->redact($value);
                continue;
            }
            $result[$key] = $value;
        }

        return $result;
    }
}
