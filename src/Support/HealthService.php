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
    private array $config;
    private bool $writeCheck;

    public function __construct(
        string $rootPath,
        callable $dbCheck,
        StorageService $storage,
        ConfigSanityChecker $configChecker,
        array $config,
        bool $writeCheck = false
    ) {
        $this->rootPath = $rootPath;
        $this->dbCheck = $dbCheck;
        $this->storage = $storage;
        $this->configChecker = $configChecker;
        $this->config = $config;
        $this->writeCheck = $writeCheck;
    }

    /** @return array{ok: bool, checks: array<string, bool>} */
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
        ];
    }

    private function checkDb(): bool
    {
        try {
            $checker = $this->dbCheck;
            return (bool) $checker();
        } catch (\Throwable) {
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
}
