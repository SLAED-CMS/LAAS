<?php

declare(strict_types=1);

namespace Laas\Support;

use Laas\Modules\Media\Service\StorageService;

final class HealthService
{
    private string $rootPath;
    /** @var callable */
    private $dbCheck;
    private StorageService $storage;
    private ConfigSanityChecker $configChecker;
    private SessionConfigValidator $sessionValidator;
    private array $config;
    private array $sessionConfig;
    private bool $writeCheck;

    public function __construct(
        string $rootPath,
        callable $dbCheck,
        StorageService $storage,
        ConfigSanityChecker $configChecker,
        array $config,
        bool $writeCheck = false,
        ?SessionConfigValidator $sessionValidator = null
    ) {
        $this->rootPath = $rootPath;
        $this->dbCheck = $dbCheck;
        $this->storage = $storage;
        $this->configChecker = $configChecker;
        $this->config = $config;
        $this->sessionConfig = $this->resolveSessionConfig($config);
        $this->sessionValidator = $sessionValidator ?? new SessionConfigValidator();
        $this->writeCheck = $writeCheck;
    }

    /** @return array{ok: bool, checks: array<string, bool>, warnings: array<int, string>} */
    public function check(): array
    {
        $checks = [
            'boot' => true,
            'config' => $this->configChecker->check($this->config) === [],
            'db' => $this->checkDb(),
            'storage' => $this->checkStorage(),
            'fs' => $this->checkFilesystem(),
        ];

        $ok = true;
        foreach ($checks as $value) {
            if ($value !== true) {
                $ok = false;
                break;
            }
        }

        return [
            'ok' => $ok,
            'checks' => $checks,
            'warnings' => array_merge(
                $this->sessionValidator->warnings($this->sessionConfig),
                $this->backupWarnings()
            ),
        ];
    }

    private function checkDb(): bool
    {
        try {
            $checker = $this->dbCheck;
            return (bool) $checker();
        } catch (\Throwable $e) {
            // Debug output for CI/test environments
            if (getenv('CI') === 'true' || getenv('APP_ENV') === 'test') {
                fwrite(STDERR, 'DEBUG: DB health check exception: ' . $e->getMessage() . "\n");
                fwrite(STDERR, 'DEBUG: Exception trace: ' . $e->getTraceAsString() . "\n");
            }
            return false;
        }
    }

    private function checkStorage(): bool
    {
        if ($this->storage->isMisconfigured()) {
            return false;
        }

        if (!$this->writeCheck) {
            $this->storage->exists('health/.probe');
            return true;
        }

        $token = bin2hex(random_bytes(6));
        $diskPath = 'health/write_check/' . $token . '.txt';
        if (!$this->storage->putContents($diskPath, 'ok')) {
            return false;
        }

        $exists = $this->storage->exists($diskPath);
        $this->storage->delete($diskPath);

        return $exists;
    }

    private function checkFilesystem(): bool
    {
        $storageRoot = $this->rootPath . '/storage';
        if (!is_dir($storageRoot) || !is_writable($storageRoot)) {
            return false;
        }

        $probe = $storageRoot . '/health_' . bin2hex(random_bytes(6)) . '.tmp';
        $written = @file_put_contents($probe, 'ok');
        if ($written === false) {
            return false;
        }

        @unlink($probe);

        $cacheDir = $storageRoot . '/cache';
        if (!is_dir($cacheDir) || !is_writable($cacheDir)) {
            return false;
        }

        return true;
    }

    private function resolveSessionConfig(array $config): array
    {
        $session = $config['session'] ?? null;
        if (is_array($session)) {
            return $session;
        }

        $security = $config['security'] ?? null;
        if (is_array($security) && is_array($security['session'] ?? null)) {
            return $security['session'];
        }

        return [];
    }

    /** @return array<int, string> */
    private function backupWarnings(): array
    {
        $warnings = [];

        $backupsDir = $this->rootPath . '/storage/backups';
        if (!is_dir($backupsDir) || !is_writable($backupsDir)) {
            $warnings[] = 'backups dir not writable';
        }

        $tmpDir = $this->rootPath . '/storage/tmp';
        if (!is_dir($tmpDir) || !is_writable($tmpDir)) {
            $warnings[] = 'tmp dir not writable';
        }

        return $warnings;
    }
}
