<?php
declare(strict_types=1);

namespace Laas\Support;

use Laas\Database\DatabaseManager;
use Laas\Database\Migrations\Migrator;
use Laas\Modules\Media\Service\StorageService;
use Laas\View\Template\TemplateCompiler;
use Laas\View\Template\TemplateEngine;
use Laas\View\Template\TemplateWarmupService;
use Laas\View\Theme\ThemeManager;

final class ReleaseChecker
{
    public function __construct(
        private string $rootPath,
        private array $appConfig,
        private array $mediaConfig,
        private array $storageConfig,
        private array $dbConfig,
        private DatabaseManager $db,
        private Migrator $migrator,
        private StorageService $storage,
        private BackupManager $backupManager
    ) {
    }

    /** @return array{ok: bool, code: int, errors: array<int, string>} */
    public function run(array $options = []): array
    {
        $errors = [];

        if (empty($options['skip_prod'])) {
            $errors = array_merge($errors, $this->checkProdConfig());
        }

        if (empty($options['skip_config'])) {
            $errors = array_merge($errors, $this->checkConfigSanity());
        }

        if (empty($options['skip_health'])) {
            $healthErrors = $this->checkHealth();
            if ($healthErrors !== []) {
                $errors = array_merge($errors, $healthErrors);
            }
        }

        if (empty($options['skip_migrations'])) {
            $errors = array_merge($errors, $this->checkMigrations());
        }

        if (empty($options['skip_backup'])) {
            $errors = array_merge($errors, $this->checkBackup((string) ($options['db_driver'] ?? 'pdo')));
        }

        if (empty($options['skip_templates'])) {
            if (!empty($options['warmup_enabled'])) {
                $errors = array_merge($errors, $this->warmupTemplates());
            }
        }

        if (empty($options['skip_composer'])) {
            if (!$this->runComposerValidate()) {
                $errors[] = 'composer_validate_failed';
            }
        }

        if (empty($options['skip_debt'])) {
            $debt = $this->scanDebt([
                $this->rootPath . '/src',
                $this->rootPath . '/modules',
                $this->rootPath . '/tools',
            ]);
            if ($debt !== []) {
                $errors[] = 'debt_found';
            }
        }

        return [
            'ok' => $errors === [],
            'code' => $errors === [] ? 0 : 1,
            'errors' => $errors,
        ];
    }

    /** @return array<int, string> */
    private function checkProdConfig(): array
    {
        $errors = [];
        $env = strtolower((string) ($this->appConfig['env'] ?? ''));
        if ($env !== 'prod') {
            return $errors;
        }

        if (!empty($this->appConfig['debug'])) {
            $errors[] = 'prod_debug_enabled';
        }

        $devtoolsEnabled = !empty($this->appConfig['devtools']['enabled'] ?? false);
        if ($devtoolsEnabled) {
            $errors[] = 'prod_devtools_enabled';
        }

        return $errors;
    }

    /** @return array<int, string> */
    private function checkConfigSanity(): array
    {
        $checker = new ConfigSanityChecker();
        return $checker->check([
            'media' => $this->mediaConfig,
            'storage' => $this->storageConfig,
            'db' => $this->dbConfig,
        ]);
    }

    /** @return array<int, string> */
    private function checkHealth(): array
    {
        $this->ensureStorageDirs();
        $this->ensureSqliteDir();
        $checker = new ConfigSanityChecker();
        $health = new HealthService(
            $this->rootPath,
            static fn (): bool => $this->db->healthCheck(),
            $this->storage,
            $checker,
            [
                'media' => $this->mediaConfig,
                'storage' => $this->storageConfig,
            ],
            false
        );

        $result = $health->check();
        if (!empty($result['ok'])) {
            return [];
        }

        $errors = [];
        $checks = $result['checks'] ?? [];
        foreach ($checks as $name => $ok) {
            if ($ok !== true) {
                $errors[] = 'health_' . $name . '_failed';
            }
        }

        if ($errors === []) {
            $errors[] = 'health_failed';
        }

        return $errors;
    }

    /** @return array<int, string> */
    private function checkMigrations(): array
    {
        $errors = [];
        $status = $this->migrator->status();
        foreach ($status as $row) {
            if (empty($row['applied'])) {
                $errors[] = 'migration_pending';
                break;
            }
        }

        return $errors;
    }

    /** @return array<int, string> */
    private function checkBackup(string $driver): array
    {
        $errors = [];
        $result = $this->backupManager->create([
            'db_driver' => $driver,
            'label' => 'release_smoke',
        ]);
        if (!$result['ok']) {
            $errors[] = 'backup_create_failed';
            return $errors;
        }

        $file = (string) ($result['file'] ?? '');
        if ($file === '' || !is_file($file)) {
            $errors[] = 'backup_missing';
            return $errors;
        }

        $inspect = $this->backupManager->inspect($file);
        if (!$inspect['ok']) {
            $errors[] = 'backup_inspect_failed';
        }

        @unlink($file);
        return $errors;
    }

    /** @return array<int, string> */
    private function warmupTemplates(): array
    {
        $errors = [];
        $themesRoot = $this->rootPath . '/themes';
        $publicTheme = (string) ($this->appConfig['theme'] ?? 'default');
        $adminTheme = 'admin';

        foreach ([$publicTheme, $adminTheme] as $theme) {
            $themeManager = new ThemeManager($themesRoot, $theme, null);
            $engine = new TemplateEngine(
                $themeManager,
                new TemplateCompiler(),
                $this->rootPath . '/storage/cache/templates',
                (bool) ($this->appConfig['debug'] ?? false)
            );
            $warmup = new TemplateWarmupService($engine);
            $result = $warmup->warmupTheme($themeManager);
            if ($result['errors'] !== []) {
                $errors[] = 'templates_warmup_failed';
                break;
            }
        }

        return $errors;
    }

    private function runComposerValidate(): bool
    {
        $cmd = stripos(PHP_OS_FAMILY, 'Windows') !== false
            ? 'composer validate --no-check-publish'
            : 'composer validate --no-check-publish';

        $output = [];
        $code = 1;
        @exec($cmd, $output, $code);
        return $code === 0;
    }

    /** @param array<int, string> $paths */
    public function scanDebt(array $paths, array $allowlist = []): array
    {
        $found = [];
        foreach ($paths as $path) {
            if (!is_dir($path)) {
                continue;
            }
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($iterator as $file) {
                if (!$file->isFile()) {
                    continue;
                }
                $filePath = $file->getPathname();
                if (str_ends_with($filePath, 'ReleaseChecker.php')) {
                    continue;
                }
                foreach ($allowlist as $allowed) {
                    if ($allowed !== '' && str_contains($filePath, $allowed)) {
                        continue 2;
                    }
                }
                $ext = strtolower($file->getExtension());
                if (!in_array($ext, ['php', 'md', 'txt'], true)) {
                    continue;
                }
                $content = @file_get_contents($filePath);
                if ($content === false) {
                    continue;
                }
                $todo = 'TO' . 'DO';
                $fixme = 'FIX' . 'ME';
                if (strpos($content, $todo) !== false || strpos($content, $fixme) !== false) {
                    $found[] = $filePath;
                }
            }
        }

        return $found;
    }

    private function ensureStorageDirs(): void
    {
        $base = $this->rootPath . '/storage';
        if (!is_dir($base)) {
            @mkdir($base, 0775, true);
        }

        $cache = $base . '/cache';
        if (!is_dir($cache)) {
            @mkdir($cache, 0775, true);
        }
    }

    private function ensureSqliteDir(): void
    {
        $driver = strtolower((string) ($this->dbConfig['driver'] ?? ''));
        if ($driver !== 'sqlite') {
            return;
        }

        $database = (string) ($this->dbConfig['database'] ?? '');
        if ($database === '' || $database === ':memory:') {
            return;
        }

        $dir = dirname($database);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
    }
}
