<?php
declare(strict_types=1);

namespace Laas\Support;

use Laas\Modules\Media\Service\StorageService;

final class OpsChecker
{
    /** @var callable */
    private $dbCheck;
    private string $rootPath;
    private StorageService $storage;
    private ConfigSanityChecker $configChecker;
    private array $config;

    public function __construct(
        string $rootPath,
        callable $dbCheck,
        StorageService $storage,
        ConfigSanityChecker $configChecker,
        array $config
    ) {
        $this->rootPath = rtrim($rootPath, '/\\');
        $this->dbCheck = $dbCheck;
        $this->storage = $storage;
        $this->configChecker = $configChecker;
        $this->config = $config;
    }

    /** @return array{code: int, checks: array<string, string>} */
    public function run(): array
    {
        $checks = [];
        $errors = 0;
        $warnings = 0;

        $sanityErrors = $this->configChecker->check($this->config);
        if ($sanityErrors !== []) {
            $checks['config'] = 'fail';
            $errors++;
        } else {
            $checks['config'] = 'ok';
        }

        if ($this->storage->isMisconfigured()) {
            $checks['storage'] = 'fail';
            $errors++;
        } else {
            $ok = $this->checkStorage();
            $checks['storage'] = $ok ? 'ok' : 'fail';
            if (!$ok) {
                $errors++;
            }
        }

        $dbConfigured = $this->dbConfigured($this->config['db'] ?? []);
        if (!$dbConfigured) {
            $checks['db'] = 'warning';
            $warnings++;
        } else {
            $ok = $this->checkDb();
            $checks['db'] = $ok ? 'ok' : 'fail';
            if (!$ok) {
                $errors++;
            }
        }

        $fsOk = $this->checkWritablePaths();
        $checks['fs'] = $fsOk ? 'ok' : 'fail';
        if (!$fsOk) {
            $errors++;
        }

        $code = 0;
        if ($errors > 0) {
            $code = 1;
        } elseif ($warnings > 0) {
            $code = 2;
        }

        return ['code' => $code, 'checks' => $checks];
    }

    private function checkStorage(): bool
    {
        try {
            if ($this->storage->driverName() === 'local') {
                $root = $this->rootPath . '/storage';
                return is_dir($root) && is_readable($root);
            }

            $this->storage->exists('health/.probe');
            return true;
        } catch (\Throwable) {
            return false;
        }
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

    private function dbConfigured(array $config): bool
    {
        $driver = (string) ($config['driver'] ?? '');
        if ($driver === 'sqlite') {
            return true;
        }

        $db = (string) ($config['database'] ?? '');
        return $db !== '';
    }

    private function checkWritablePaths(): bool
    {
        $paths = [
            $this->rootPath . '/storage',
            $this->rootPath . '/storage/logs',
            $this->rootPath . '/storage/sessions',
            $this->rootPath . '/storage/cache',
            $this->rootPath . '/storage/backups',
        ];

        foreach ($paths as $path) {
            if (!is_dir($path) || !is_writable($path)) {
                return false;
            }
        }

        return true;
    }
}
