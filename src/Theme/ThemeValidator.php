<?php

declare(strict_types=1);

namespace Laas\Theme;

final class ThemeValidator
{
    private const SEMVER_PATTERN = '/^\\d+\\.\\d+\\.\\d+(-[0-9A-Za-z.-]+)?(\\+[0-9A-Za-z.-]+)?$/';

    /** @var array<int, string> */
    private array $cdnPatterns = [
        'https://cdn.',
        'https://cdn.jsdelivr.net',
        'https://unpkg.com',
        'https://cdnjs.cloudflare.com',
        'https://fonts.googleapis.com',
        'https://googleapis.com',
    ];

    /** @var array<int, string> */
    private array $requiredPartials = [
        'partials/header.html',
    ];

    public function __construct(
        private string $themesRoot,
        private ?string $snapshotPath = null,
        private array|string|null $compatConfig = null,
        ?array $compatOverride = null
    ) {
        $compatCandidate = $this->compatConfig;
        if ($this->snapshotPath === null && is_string($compatCandidate)) {
            $this->snapshotPath = $compatCandidate;
            $compatCandidate = null;
        }
        if (is_array($compatOverride)) {
            $compatCandidate = $compatOverride;
        }
        if ($this->snapshotPath === null) {
            $root = dirname(__DIR__, 2);
            $this->snapshotPath = $root . '/config/theme_snapshot.php';
        }
        if (!is_array($compatCandidate)) {
            $compatCandidate = $this->loadCompatConfig();
        }
        $this->compatConfig = $compatCandidate;
    }

    public function validateTheme(string $themeName, bool $acceptSnapshot = false): ThemeValidationResult
    {
        $result = new ThemeValidationResult($themeName);
        $themePath = rtrim($this->themesRoot, '/\\') . '/' . $themeName;

        $manifestPath = $themePath . '/theme.json';
        if (!is_file($manifestPath)) {
            $result->addViolation('theme_json_missing', $manifestPath, 'Missing theme.json');
        } else {
            $data = $this->readThemeJson($manifestPath);
            if ($data === null) {
                $result->addViolation('theme_json_invalid', $manifestPath, 'Invalid theme.json');
            } else {
                $this->validateSchema($result, $manifestPath, $data);
                $this->validateCapabilities($result, $manifestPath, $data);
                $this->validateSnapshot($result, $manifestPath, $data, $acceptSnapshot);
            }
        }

        $baseLayout = $themePath . '/layouts/base.html';
        if (!is_file($baseLayout)) {
            $result->addViolation('layout_missing', $baseLayout, 'Missing layouts/base.html');
        }

        foreach ($this->requiredPartials as $partial) {
            $path = $themePath . '/' . ltrim($partial, '/\\');
            if (!is_file($path)) {
                $result->addViolation('partial_missing', $path, 'Missing required partial: ' . $partial);
            }
        }

        foreach ($this->listHtmlFiles($themePath) as $file) {
            $contents = @file_get_contents($file);
            if (!is_string($contents)) {
                continue;
            }

            if ($this->hasInlineStyle($contents)) {
                $result->addViolation('inline_style', $file, 'Inline <style> is forbidden');
            }

            if ($this->hasInlineScript($contents)) {
                $result->addViolation('inline_script', $file, 'Inline <script> is forbidden');
            }

            foreach ($this->cdnPatterns as $pattern) {
                if (stripos($contents, $pattern) !== false) {
                    $result->addViolation('cdn_usage', $file, 'CDN usage is forbidden');
                    break;
                }
            }
        }

        return $result;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function readThemeJson(string $path): ?array
    {
        $raw = @file_get_contents($path);
        if (!is_string($raw)) {
            return null;
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return null;
        }
        return $data;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function validateSchema(ThemeValidationResult $result, string $path, array $data): void
    {
        $name = $data['name'] ?? null;
        if (!is_string($name) || trim($name) === '') {
            $result->addViolation('theme_schema', $path, 'Missing or invalid name');
        }

        $version = $data['version'] ?? null;
        if (!is_string($version) || preg_match(self::SEMVER_PATTERN, $version) !== 1) {
            $result->addViolation('theme_schema', $path, 'Missing or invalid version');
        }

        $api = $data['api'] ?? null;
        if (!is_string($api) || $api !== 'v2') {
            if ($this->compatThemeApiV1()) {
                $result->addWarning('theme_api_compat', $path, 'Theme api is not v2 (compat mode)');
            } else {
                $result->addViolation('theme_api', $path, 'Theme api must be v2');
            }
        }

        if (isset($data['capabilities']) && !is_array($data['capabilities'])) {
            $result->addViolation('theme_schema', $path, 'capabilities must be an array of strings');
        }
        if (isset($data['provides']) && !is_array($data['provides'])) {
            $result->addViolation('theme_schema', $path, 'provides must be an array of strings');
        }
        if (isset($data['meta']) && !is_array($data['meta'])) {
            $result->addViolation('theme_schema', $path, 'meta must be an object');
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    private function validateCapabilities(ThemeValidationResult $result, string $path, array $data): void
    {
        if (!isset($data['capabilities']) || !is_array($data['capabilities'])) {
            return;
        }

        $caps = ThemeCapabilities::normalize($data['capabilities']);
        $allowlist = ThemeCapabilities::allowlist();
        foreach ($caps as $cap) {
            if (!in_array($cap, $allowlist, true)) {
                $result->addViolation('theme_capability_unknown', $path, 'Unknown capability: ' . $cap);
            }
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    private function validateSnapshot(ThemeValidationResult $result, string $path, array $data, bool $acceptSnapshot): void
    {
        $snapshotPath = $this->snapshotPath ?? '';
        $snapshot = $this->readSnapshot($snapshotPath);
        if ($snapshot === null) {
            if (!$acceptSnapshot) {
                $result->addViolation('theme_snapshot_missing', $snapshotPath, 'Missing theme snapshot');
            }
            return;
        }

        $hash = hash_file('sha256', $path);
        if (!is_string($hash) || $hash === '') {
            return;
        }

        $themes = $snapshot['themes'] ?? [];
        $entry = $themes[$result->getThemeName()] ?? null;
        $expected = is_array($entry) ? (string) ($entry['sha256'] ?? '') : (string) $entry;
        if ($expected === '' || strtolower($expected) !== strtolower($hash)) {
            if ($acceptSnapshot) {
                $this->writeSnapshot($snapshotPath, $snapshot, $result->getThemeName(), $hash, $path);
                return;
            }
            $result->addViolation('theme_snapshot_mismatch', $path, 'theme.json changed; accept snapshot to update');
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function readSnapshot(string $path): ?array
    {
        if ($path === '' || !is_file($path)) {
            return null;
        }
        $data = require $path;
        return is_array($data) ? $data : null;
    }

    /**
     * @param array<string, mixed> $snapshot
     */
    private function writeSnapshot(string $path, array $snapshot, string $themeName, string $hash, string $themePath): void
    {
        $snapshot['version'] = (int) ($snapshot['version'] ?? 1);
        $snapshot['generated_at'] = gmdate('c');
        if (!isset($snapshot['themes']) || !is_array($snapshot['themes'])) {
            $snapshot['themes'] = [];
        }
        $snapshot['themes'][$themeName] = [
            'sha256' => $hash,
            'path' => $this->relativePath($themePath),
        ];

        $export = var_export($snapshot, true);
        $contents = "<?php\n" . "declare(strict_types=1);\n\nreturn " . $export . ";\n";
        @file_put_contents($path, $contents);
    }

    private function relativePath(string $path): string
    {
        $root = dirname(__DIR__, 2);
        $normalized = str_replace('\\', '/', $path);
        $root = str_replace('\\', '/', $root);
        if (str_starts_with($normalized, $root . '/')) {
            return ltrim(substr($normalized, strlen($root)), '/');
        }
        return $normalized;
    }

    private function hasInlineStyle(string $contents): bool
    {
        return preg_match('/<style\\b/i', $contents) === 1;
    }

    private function hasInlineScript(string $contents): bool
    {
        if (preg_match_all('/<script\\b[^>]*>/i', $contents, $matches) <= 0) {
            return false;
        }
        foreach ($matches[0] as $tag) {
            if (preg_match('/\\bsrc\\s*=\\s*/i', $tag) === 1) {
                continue;
            }
            return true;
        }
        return false;
    }

    /**
     * @return array<int, string>
     */
    private function listHtmlFiles(string $path): array
    {
        $files = [];
        if (!is_dir($path)) {
            return $files;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }
            if (strtolower($file->getExtension()) !== 'html') {
                continue;
            }
            $files[] = $file->getPathname();
        }

        return $files;
    }

    private function compatThemeApiV1(): bool
    {
        return (bool) ($this->compatConfig['compat_theme_api_v1'] ?? false);
    }

    private function loadCompatConfig(): array
    {
        $root = dirname(__DIR__, 2);
        $path = $root . '/config/compat.php';
        if (!is_file($path)) {
            return [];
        }
        $data = require $path;
        return is_array($data) ? $data : [];
    }
}
