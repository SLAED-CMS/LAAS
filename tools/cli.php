<?php
declare(strict_types=1);

use Laas\Database\DatabaseManager;
use Laas\Database\Migrations\Migrator;
use Laas\Database\Repositories\ModulesRepository;
use Laas\Database\Repositories\PermissionsRepository;
use Laas\Database\Repositories\RbacRepository;
use Laas\Database\Repositories\RolesRepository;
use Laas\Database\Repositories\SettingsRepository;
use Laas\Database\Repositories\UsersRepository;
use Laas\Modules\Media\Repository\MediaRepository;
use Laas\Modules\Media\Service\MediaThumbnailService;
use Laas\Modules\Media\Service\StorageService;
use Laas\Support\AuditLogger;
use Laas\Support\BackupManager;
use Laas\Support\ConfigSanityChecker;
use Laas\Support\ConfigExporter;
use Laas\Support\LoggerFactory;
use Laas\Support\OpsChecker;
use Laas\Support\ReleaseChecker;
use Laas\Support\PreflightRunner;
use Laas\Ops\Checks\SessionCheck;
use Laas\Session\SessionFactory;
use Laas\I18n\Translator;
use Laas\Http\Contract\ContractRegistry;
use Laas\Http\Contract\ContractFixtureNormalizer;
use Laas\View\Template\TemplateCompiler;
use Laas\View\Template\TemplateEngine;
use Laas\View\Template\TemplateWarmupService;
use Laas\View\Theme\ThemeManager;

$rootPath = dirname(__DIR__);
require $rootPath . '/vendor/autoload.php';

if (class_exists(\Dotenv\Dotenv::class)) {
    \Dotenv\Dotenv::createImmutable($rootPath)->safeLoad();
}
$_ENV = array_merge($_ENV, getenv());

$command = $argv[1] ?? '';
$args = array_slice($argv, 2);

$appConfig = require $rootPath . '/config/app.php';
$securityConfig = require $rootPath . '/config/security.php';
$dbConfig = require $rootPath . '/config/database.php';
$modulesConfig = require $rootPath . '/config/modules.php';
$storageConfig = is_file($rootPath . '/config/storage.php') ? require $rootPath . '/config/storage.php' : [];

$logger = (new LoggerFactory($rootPath))->create($appConfig);
$dbManager = new DatabaseManager($dbConfig);
$migrator = new Migrator($dbManager, $rootPath, $modulesConfig, [
    'app' => $appConfig,
    'logger' => $logger,
    'is_cli' => true,
], $logger);
$modulesRepo = null;
$rbacRepo = null;
$rolesRepo = null;
$permissionsRepo = null;
$usersRepo = null;

try {
    if ($dbManager->healthCheck()) {
        $modulesRepo = new ModulesRepository($dbManager->pdo());
        $rbacRepo = new RbacRepository($dbManager->pdo());
        $rolesRepo = new RolesRepository($dbManager->pdo());
        $permissionsRepo = new PermissionsRepository($dbManager->pdo());
        $usersRepo = new UsersRepository($dbManager->pdo());
    }
} catch (Throwable) {
    $modulesRepo = null;
    $rbacRepo = null;
    $rolesRepo = null;
    $permissionsRepo = null;
    $usersRepo = null;
}

$commands = [];

$commands['templates:clear'] = function () use ($rootPath): int {
    $dir = $rootPath . '/storage/cache/templates';
    if (!is_dir($dir)) {
        echo "Templates cache directory not found.\n";
        return 1;
    }

    $deleted = 0;
    $items = glob($dir . '/*.php') ?: [];
    foreach ($items as $file) {
        if (is_file($file)) {
            unlink($file);
            $deleted++;
        }
    }

    echo "Templates cache cleared: {$deleted} file(s) removed.\n";
    return 0;
};

$commands['cache:clear'] = function () use (&$commands): int {
    return $commands['templates:clear']();
};

$commands['templates:warmup'] = function () use ($rootPath, $appConfig): int {
    $start = microtime(true);
    $translator = new Translator(
        $rootPath,
        (string) ($appConfig['theme'] ?? 'default'),
        (string) ($appConfig['default_locale'] ?? 'en')
    );

    $themesRoot = $rootPath . '/themes';
    $publicTheme = (string) ($appConfig['theme'] ?? 'default');
    $adminTheme = 'admin';

    $compiled = 0;
    $errors = [];

    foreach ([$publicTheme, $adminTheme] as $theme) {
        $themeManager = new ThemeManager($themesRoot, $theme, null);
        $engine = new TemplateEngine(
            $themeManager,
            new TemplateCompiler(),
            $rootPath . '/storage/cache/templates',
            (bool) ($appConfig['debug'] ?? false)
        );
        $warmup = new TemplateWarmupService($engine);
        $result = $warmup->warmupTheme($themeManager);
        $compiled += $result['compiled'];
        $errors = array_merge($errors, $result['errors']);
    }

    $elapsedMs = (int) ((microtime(true) - $start) * 1000);
    if ($errors !== []) {
        echo $translator->trans('cache.warmup.failed') . "\n";
        foreach ($errors as $error) {
            echo $error . "\n";
        }
        return 1;
    }

    $message = $translator->trans('cache.warmup.ok');
    $compiledMessage = $translator->trans('cache.warmup.compiled', ['count' => $compiled]);
    echo $message . "\n";
    echo $compiledMessage . ' (' . $elapsedMs . "ms)\n";
    return 0;
};

$commands['db:check'] = function () use ($dbManager): int {
    try {
        $ok = $dbManager->healthCheck();
        echo $ok ? "DB: OK\n" : "DB: FAIL\n";
        return $ok ? 0 : 1;
    } catch (Throwable $e) {
        echo "DB: FAIL - " . $e->getMessage() . "\n";
        return 1;
    }
};

$commands['migrate:status'] = function () use ($migrator): int {
    try {
        $status = $migrator->status();
        foreach ($status as $row) {
            $mark = $row['applied'] ? '[x]' : '[ ]';
            $batch = $row['batch'] !== null ? (' batch=' . $row['batch']) : '';
            echo $mark . ' ' . $row['migration'] . $batch . "\n";
        }
        return 0;
    } catch (Throwable $e) {
        echo "Migrate status failed: " . $e->getMessage() . "\n";
        return 1;
    }
};

$commands['migrate:up'] = function () use ($migrator, $args): int {
    try {
        $steps = (int) (getOption($args, 'steps') ?? 0);
        $applied = $migrator->up($steps);
        if ($applied === []) {
            echo "No migrations to apply.\n";
            return 0;
        }
        foreach ($applied as $name) {
            echo "Applied: {$name}\n";
        }
        return 0;
    } catch (Throwable $e) {
        echo "Migrate up failed: " . $e->getMessage() . "\n";
        return 1;
    }
};

$commands['migrate:down'] = function () use ($migrator, $args): int {
    try {
        $steps = (int) (getOption($args, 'steps') ?? 1);
        $rolled = $migrator->down($steps);
        if ($rolled === []) {
            echo "No migrations to rollback.\n";
            return 0;
        }
        foreach ($rolled as $name) {
            echo "Rolled back: {$name}\n";
        }
        return 0;
    } catch (Throwable $e) {
        echo "Migrate down failed: " . $e->getMessage() . "\n";
        return 1;
    }
};

$commands['migrate:refresh'] = function () use ($migrator): int {
    try {
        $result = $migrator->refresh();
        foreach ($result as $name) {
            echo "Processed: {$name}\n";
        }
        return 0;
    } catch (Throwable $e) {
        echo "Migrate refresh failed: " . $e->getMessage() . "\n";
        return 1;
    }
};

$commands['module:status'] = function () use ($modulesRepo, $modulesConfig, $rootPath): int {
    $discovered = discoverModules($rootPath);
    $enabledConfig = [];
    foreach ($modulesConfig as $class) {
        $parts = explode('\\', trim($class, '\\'));
        $enabledConfig[] = $parts[2] ?? '';
    }

    $source = 'CONFIG';
    $enabled = $enabledConfig;

    if ($modulesRepo !== null) {
        try {
            $modulesRepo->sync($discovered, $enabledConfig);
            $all = $modulesRepo->all();
            $enabled = [];
            foreach ($all as $name => $row) {
                if (!empty($row['enabled'])) {
                    $enabled[] = $name;
                }
            }
            $source = 'DB';
        } catch (Throwable) {
            $source = 'CONFIG';
            $enabled = $enabledConfig;
        }
    }

    foreach ($discovered as $name => $meta) {
        $isEnabled = in_array($name, $enabled, true);
        $version = $meta['version'] ?? '';
        $version = $version !== null ? (string) $version : '';
        $type = (string) ($meta['type'] ?? '');
        echo $name . ' | ' . ($isEnabled ? 'ON' : 'OFF') . ' | ' . $source;
        if ($version !== '') {
            echo ' | ' . $version;
        }
        if ($type !== '') {
            echo ' | ' . $type;
        }
        echo "\n";
    }

    return 0;
};

$commands['module:sync'] = function () use ($modulesRepo, $modulesConfig, $rootPath): int {
    if ($modulesRepo === null) {
        echo "DB not available. Enable modules via config/modules.php\n";
        return 1;
    }

    $discovered = discoverModules($rootPath);
    $enabledConfig = [];
    foreach ($modulesConfig as $class) {
        $parts = explode('\\', trim($class, '\\'));
        $enabledConfig[] = $parts[2] ?? '';
    }

    $modulesRepo->sync($discovered, $enabledConfig);
    echo "Modules synced.\n";
    return 0;
};

$commands['module:enable'] = function () use ($modulesRepo, $args, $rootPath): int {
    if ($modulesRepo === null) {
        echo "DB not available. Enable modules via config/modules.php\n";
        return 1;
    }

    $name = $args[0] ?? '';
    if ($name === '') {
        echo "Usage: module:enable ModuleName\n";
        return 1;
    }

    $discovered = discoverModules($rootPath);
    $type = $discovered[$name]['type'] ?? null;
    if ($type !== 'feature') {
        $typeLabel = $type ?? 'unknown';
        echo "Module {$name} is {$typeLabel} and cannot be toggled.\n";
        return 1;
    }

    $modulesRepo->enable($name);
    echo "Enabled: {$name}\n";
    return 0;
};

$commands['module:disable'] = function () use ($modulesRepo, $args, $rootPath): int {
    if ($modulesRepo === null) {
        echo "DB not available. Disable modules via config/modules.php\n";
        return 1;
    }

    $name = $args[0] ?? '';
    if ($name === '') {
        echo "Usage: module:disable ModuleName\n";
        return 1;
    }

    $discovered = discoverModules($rootPath);
    $type = $discovered[$name]['type'] ?? null;
    if ($type !== 'feature') {
        $typeLabel = $type ?? 'unknown';
        echo "Module {$name} is {$typeLabel} and cannot be toggled.\n";
        return 1;
    }

    $modulesRepo->disable($name);
    echo "Disabled: {$name}\n";
    return 0;
};

$commands['settings:get'] = function () use ($dbManager, $args): int {
    $key = $args[0] ?? '';
    if ($key === '') {
        echo "Usage: settings:get KEY\n";
        return 1;
    }

    if (!$dbManager->healthCheck()) {
        echo "DB not available.\n";
        return 1;
    }

    $settings = new SettingsRepository($dbManager->pdo());
    $value = $settings->get($key, null);
    if (is_array($value)) {
        echo json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
        return 0;
    }

    echo (string) $value . "\n";
    return 0;
};

$commands['settings:set'] = function () use ($dbManager, $args): int {
    $key = $args[0] ?? '';
    $value = $args[1] ?? null;
    $type = (string) (getOption($args, 'type') ?? 'string');

    if ($key === '' || $value === null) {
        echo "Usage: settings:set KEY VALUE [--type=string|int|bool|json]\n";
        return 1;
    }

    if (!$dbManager->healthCheck()) {
        echo "DB not available.\n";
        return 1;
    }

    $settings = new SettingsRepository($dbManager->pdo());
    $settings->set($key, $value, $type);
    echo "OK\n";
    return 0;
};

$commands['rbac:status'] = function () use ($rolesRepo, $permissionsRepo, $usersRepo, $rbacRepo): int {
    if ($rolesRepo === null || $permissionsRepo === null || $usersRepo === null || $rbacRepo === null) {
        echo "DB not available.\n";
        return 1;
    }

    $roleId = $rolesRepo->findIdByName('admin');
    $permId = $permissionsRepo->findIdByName('admin.access');
    echo "role admin: " . ($roleId !== null ? 'OK' : 'MISSING') . "\n";
    echo "permission admin.access: " . ($permId !== null ? 'OK' : 'MISSING') . "\n";

    $admin = $usersRepo->findByUsername('admin');
    if ($admin === null) {
        echo "user admin: MISSING\n";
        return 0;
    }

    $roles = $rbacRepo->getUserRoles((int) $admin['id']);
    echo "user admin roles: " . ($roles !== [] ? implode(', ', $roles) : 'none') . "\n";
    return 0;
};

$commands['rbac:grant'] = function () use ($rolesRepo, $permissionsRepo, $usersRepo, $rbacRepo, $args): int {
    if ($rolesRepo === null || $permissionsRepo === null || $usersRepo === null || $rbacRepo === null) {
        echo "DB not available.\n";
        return 1;
    }

    $username = $args[0] ?? '';
    $permission = $args[1] ?? '';
    if ($username === '' || $permission === '') {
        echo "Usage: rbac:grant <username> <permission>\n";
        return 1;
    }

    $user = $usersRepo->findByUsername($username);
    if ($user === null) {
        echo "User not found: {$username}\n";
        return 1;
    }

    $rolesRepo->ensureRole('admin', 'Administrator');
    $permissionsRepo->ensurePermission($permission, null);
    $rbacRepo->grantPermissionToRole('admin', $permission);
    $rbacRepo->grantRoleToUser((int) $user['id'], 'admin');
    echo "Granted {$permission} to {$username} via role admin\n";
    return 0;
};

$commands['rbac:revoke'] = function () use ($usersRepo, $rbacRepo, $args): int {
    if ($usersRepo === null || $rbacRepo === null) {
        echo "DB not available.\n";
        return 1;
    }

    $username = $args[0] ?? '';
    $permission = $args[1] ?? '';
    if ($username === '' || $permission === '') {
        echo "Usage: rbac:revoke <username> <permission>\n";
        return 1;
    }

    $user = $usersRepo->findByUsername($username);
    if ($user === null) {
        echo "User not found: {$username}\n";
        return 1;
    }

    $rbacRepo->revokePermissionFromRole('admin', $permission);
    echo "Revoked {$permission} from role admin\n";
    return 0;
};

$commands['media:thumbs:sync'] = function () use ($dbManager, $rootPath): int {
    try {
        if (!$dbManager->healthCheck()) {
            echo "DB not available.\n";
            return 1;
        }

        $repo = new MediaRepository($dbManager);
        $config = require $rootPath . '/config/media.php';
        $storage = new StorageService($rootPath);
        $service = new MediaThumbnailService($storage, null, new AuditLogger($dbManager));

        $total = $repo->count('');
        $limit = 100;
        $offset = 0;
        $processed = 0;
        $generated = 0;
        $skipped = 0;
        $failed = 0;

        while ($offset < $total) {
            $rows = $repo->list($limit, $offset, '');
            foreach ($rows as $row) {
                $processed++;
                $result = $service->sync($row, is_array($config) ? $config : []);
                $generated += $result['generated'];
                $skipped += $result['skipped'];
                $failed += $result['failed'];

                if ($processed % 50 === 0) {
                    echo "Processed {$processed}/{$total} | generated={$generated} skipped={$skipped} failed={$failed}\n";
                }
            }
            $offset += $limit;
        }

        echo "Done. processed={$processed} generated={$generated} skipped={$skipped} failed={$failed}\n";
        return 0;
    } catch (Throwable $e) {
        echo "Thumb sync failed: " . $e->getMessage() . "\n";
        return 1;
    }
};

$commands['backup:create'] = function () use ($rootPath, $dbManager, $appConfig, $storageConfig, $args): int {
    try {
        if (!$dbManager->healthCheck()) {
            echo "DB not available.\n";
            return 1;
        }

        $storage = new StorageService($rootPath);
        $manager = new BackupManager($rootPath, $dbManager, $storage, $appConfig, $storageConfig);
        $driver = (string) (getOption($args, 'db-driver') ?? 'auto');
        $result = $manager->create(['db_driver' => $driver]);
        if (!$result['ok']) {
            echo "Backup failed.\n";
            return 1;
        }

        $translator = new Translator(
            $rootPath,
            (string) ($appConfig['theme'] ?? 'default'),
            (string) ($appConfig['default_locale'] ?? 'en')
        );
        $message = $translator->trans('system.backup.created');
        echo $message . ': ' . ($result['file'] ?? '') . "\n";
        $driverKey = ($result['driver'] ?? '') === 'mysqldump'
            ? 'backup.create.driver_mysqldump'
            : 'backup.create.driver_pdo';
        echo $translator->trans($driverKey) . "\n";
        return 0;
    } catch (Throwable) {
        echo "Backup failed.\n";
        return 1;
    }
};

$commands['backup:restore'] = function () use ($rootPath, $dbManager, $appConfig, $storageConfig, $args): int {
    $file = $args[0] ?? '';
    if ($file === '') {
        echo "Usage: backup:restore <file>\n";
        return 1;
    }

    if (!$dbManager->healthCheck()) {
        echo "DB not available.\n";
        return 1;
    }

    $translator = new Translator(
        $rootPath,
        (string) ($appConfig['theme'] ?? 'default'),
        (string) ($appConfig['default_locale'] ?? 'en')
    );
    $prompt1 = $translator->trans('backup.restore.confirm_1');
    echo $prompt1 . "\n> ";
    $input1 = trim((string) fgets(STDIN));
    $prompt2 = $translator->trans('backup.restore.confirm_2');
    echo $prompt2 . "\n> ";
    $input2 = trim((string) fgets(STDIN));
    if ($input1 !== 'RESTORE' || $input2 !== basename($file)) {
        echo "Aborted.\n";
        return 1;
    }

    $storage = new StorageService($rootPath);
    $manager = new BackupManager($rootPath, $dbManager, $storage, $appConfig, $storageConfig);
    $result = $manager->restore($file, [
        'force' => hasFlag($args, 'force'),
        'dry_run' => hasFlag($args, 'dry-run'),
        'confirm1' => $input1,
        'confirm2' => $input2,
    ]);
    if (!$result['ok']) {
        $error = $result['error'] ?? '';
        if ($error === 'forbidden_in_prod') {
            echo $translator->trans('backup.restore.forbidden_in_prod') . "\n";
        } elseif ($error === 'locked') {
            echo $translator->trans('backup.restore.locked') . "\n";
        } else {
            echo $translator->trans('backup.restore.failed') . "\n";
        }
        return 1;
    }

    if (hasFlag($args, 'dry-run')) {
        echo $translator->trans('backup.restore.dry_run_ok') . "\n";
        return 0;
    }

    echo "OK\n";
    return 0;
};

$commands['backup:inspect'] = function () use ($rootPath, $dbManager, $appConfig, $storageConfig, $args): int {
    $file = $args[0] ?? '';
    if ($file === '') {
        echo "Usage: backup:inspect <file>\n";
        return 1;
    }

    $storage = new StorageService($rootPath);
    $manager = new BackupManager($rootPath, $dbManager, $storage, $appConfig, $storageConfig);
    $result = $manager->inspect($file);

    $translator = new Translator(
        $rootPath,
        (string) ($appConfig['theme'] ?? 'default'),
        (string) ($appConfig['default_locale'] ?? 'en')
    );

    if (!$result['ok']) {
        echo $translator->trans('backup.inspect.failed') . "\n";
        return 1;
    }

    $meta = $result['metadata'] ?? [];
    $checks = $result['checks'] ?? [];
    $top = $result['top'] ?? [];

    echo $translator->trans('backup.inspect.ok') . "\n";
    foreach ($meta as $key => $value) {
        echo $key . ': ' . (is_scalar($value) ? (string) $value : json_encode($value)) . "\n";
    }
    foreach ($checks as $key => $value) {
        echo 'check.' . $key . ': ' . ($value ? 'ok' : 'fail') . "\n";
    }
    foreach ($top as $key => $value) {
        echo 'size.' . $key . ': ' . $value . "\n";
    }
    return 0;
};

$commands['ops:check'] = function () use ($rootPath, $dbManager, $appConfig, $storageConfig): int {
    $storage = new StorageService($rootPath);
    $checker = new ConfigSanityChecker();
    $mediaConfig = is_file($rootPath . '/config/media.php') ? require $rootPath . '/config/media.php' : [];
    $dbConfig = is_file($rootPath . '/config/database.php') ? require $rootPath . '/config/database.php' : [];

    $ops = new OpsChecker(
        $rootPath,
        static fn (): bool => $dbManager->healthCheck(),
        $storage,
        $checker,
        [
            'media' => is_array($mediaConfig) ? $mediaConfig : [],
            'storage' => $storageConfig,
            'db' => is_array($dbConfig) ? $dbConfig : [],
        ]
    );

    $result = $ops->run();
    foreach ($result['checks'] as $name => $status) {
        echo $name . ': ' . $status . "\n";
    }

    return $result['code'];
};

$commands['release:check'] = function () use ($rootPath, $dbManager, $appConfig, $storageConfig, $migrator): int {
    $mediaConfig = is_file($rootPath . '/config/media.php') ? require $rootPath . '/config/media.php' : [];
    $dbConfig = is_file($rootPath . '/config/database.php') ? require $rootPath . '/config/database.php' : [];
    $storage = new StorageService($rootPath);
    $backup = new BackupManager($rootPath, $dbManager, $storage, $appConfig, $storageConfig);

    $checker = new ReleaseChecker(
        $rootPath,
        $appConfig,
        is_array($mediaConfig) ? $mediaConfig : [],
        $storageConfig,
        is_array($dbConfig) ? $dbConfig : [],
        $dbManager,
        $migrator,
        $storage,
        $backup
    );

    $translator = new Translator(
        $rootPath,
        (string) ($appConfig['theme'] ?? 'default'),
        (string) ($appConfig['default_locale'] ?? 'en')
    );

    $warmupEnabled = filter_var($_ENV['TEMPLATES_WARMUP_ENABLED'] ?? false, FILTER_VALIDATE_BOOLEAN);
    $dbDriver = (string) ($_ENV['RELEASE_CHECK_DB_DRIVER'] ?? 'pdo');

    $result = $checker->run([
        'warmup_enabled' => $warmupEnabled,
        'db_driver' => $dbDriver,
    ]);

    if (!$result['ok']) {
        echo $translator->trans('release.check.failed') . "\n";
        foreach ($result['errors'] as $error) {
            if ($error === 'debt_found') {
                echo $translator->trans('release.debt.todo_found') . "\n";
                continue;
            }
            if ($error === 'prod_devtools_enabled') {
                echo $translator->trans('release.devtools.disabled_in_prod') . "\n";
                continue;
            }
            echo '- ' . $error . "\n";
        }
        return 1;
    }

    echo $translator->trans('release.check.ok') . "\n";
    return 0;
};

$commands['config:export'] = function () use ($rootPath, $dbManager, $appConfig, $storageConfig, $modulesConfig): int {
    $mediaConfig = is_file($rootPath . '/config/media.php') ? require $rootPath . '/config/media.php' : [];
    $translator = new Translator(
        $rootPath,
        (string) ($appConfig['theme'] ?? 'default'),
        (string) ($appConfig['default_locale'] ?? 'en')
    );

    $settingsProvider = new \Laas\Settings\SettingsProvider(
        $dbManager,
        [
            'site_name' => $appConfig['name'] ?? 'LAAS',
            'default_locale' => $appConfig['default_locale'] ?? 'en',
            'theme' => $appConfig['theme'] ?? 'default',
        ],
        ['site_name', 'default_locale', 'theme']
    );

    $warnings = [];
    $schemaVersion = null;
    if (!$dbManager->healthCheck()) {
        $warnings[] = 'db_unavailable';
    } else {
        try {
            $stmt = $dbManager->pdo()->query('SELECT migration FROM migrations ORDER BY id DESC LIMIT 1');
            if ($stmt !== false) {
                $row = $stmt->fetch();
                if (is_array($row) && isset($row['migration'])) {
                    $schemaVersion = (string) $row['migration'];
                }
            }
        } catch (Throwable) {
            $schemaVersion = null;
        }
    }

    $exporter = new ConfigExporter(
        $rootPath,
        $appConfig,
        is_array($mediaConfig) ? $mediaConfig : [],
        $storageConfig,
        $modulesConfig,
        $settingsProvider->all(),
        $schemaVersion
    );

    $pretty = hasFlag($args, 'pretty');
    $redact = true;
    $redactOpt = getOption($args, 'redact');
    if ($redactOpt !== null) {
        $parsed = filter_var($redactOpt, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        $redact = $parsed ?? true;
    }
    $out = getOption($args, 'out');

    $snapshot = $exporter->buildSnapshot($redact, $warnings);
    $json = $exporter->toJson($snapshot, $pretty);
    if ($json === '') {
        echo $translator->trans('config.export.failed') . "\n";
        return 1;
    }

    if ($out !== null && $out !== '') {
        if (!$exporter->writeAtomic($out, $json)) {
            echo $translator->trans('config.export.failed') . "\n";
            return 1;
        }
        echo str_replace('{file}', $out, $translator->trans('config.export.wrote')) . "\n";
        return 0;
    }

    echo $json;
    fwrite(STDERR, $translator->trans('config.export.ok') . "\n");
    return 0;
};

$commands['contracts:dump'] = function (): int {
    $payload = [
        'contracts_version' => ContractRegistry::version(),
        'items' => ContractRegistry::all(),
    ];
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    return 0;
};

$commands['contracts:fixtures:dump'] = function () use ($rootPath, $args): int {
    $dir = $rootPath . '/tests/fixtures/contracts';
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }

    $force = hasFlag($args, 'force');
    $fixtures = collectContractFixtures(ContractRegistry::all());
    if ($fixtures === []) {
        echo "No contract fixtures defined.\n";
        return 1;
    }

    foreach ($fixtures as $fixture) {
        $file = $dir . '/' . $fixture['fixture'] . '.json';
        if (is_file($file) && !$force) {
            echo "Exists: {$file}\n";
            return 1;
        }
    }

    foreach ($fixtures as $fixture) {
        $file = $dir . '/' . $fixture['fixture'] . '.json';
        $payload = ContractFixtureNormalizer::normalize($fixture['payload']);
        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            echo "Failed to encode: {$fixture['fixture']}\n";
            return 1;
        }
        file_put_contents($file, $json . "\n");
        echo "Wrote: {$file}\n";
    }

    return 0;
};

$commands['contracts:fixtures:check'] = function () use ($rootPath): int {
    $dir = $rootPath . '/tests/fixtures/contracts';
    if (!is_dir($dir)) {
        echo "Fixtures directory not found.\n";
        return 1;
    }

    $files = glob($dir . '/*.json') ?: [];
    if ($files === []) {
        echo "No fixtures found.\n";
        return 1;
    }

    $contracts = ContractRegistry::all();
    $names = [];
    $specByName = [];
    foreach ($contracts as $spec) {
        $name = is_string($spec['name'] ?? null) ? $spec['name'] : '';
        if ($name !== '') {
            $names[] = $name;
            $specByName[$name] = $spec;
        }
    }

    $errors = 0;
    foreach ($files as $file) {
        $raw = (string) file_get_contents($file);
        $payload = json_decode($raw, true);
        if (!is_array($payload)) {
            echo "Invalid JSON: {$file}\n";
            $errors++;
            continue;
        }

        $fixtureName = basename($file, '.json');
        $contractName = findContractNameForFixture($fixtureName, $names);
        if ($contractName === null) {
            echo "Unknown fixture contract: {$fixtureName}\n";
            $errors++;
            continue;
        }

        if (!array_key_exists('data', $payload) && !array_key_exists('error', $payload)) {
            echo "Missing data/error: {$fixtureName}\n";
            $errors++;
        }

        $meta = $payload['meta'] ?? null;
        if (!is_array($meta)) {
            echo "Missing meta: {$fixtureName}\n";
            $errors++;
            continue;
        }

        if (($meta['format'] ?? null) !== 'json') {
            echo "Invalid meta.format: {$fixtureName}\n";
            $errors++;
        }

        $route = $meta['route'] ?? null;
        $specRoute = $specByName[$contractName]['route'] ?? null;
        $routeOk = $route === $contractName || (is_string($specRoute) && $route === $specRoute);
        if (!$routeOk) {
            echo "Invalid meta.route: {$fixtureName}\n";
            $errors++;
        }
    }

    if ($errors > 0) {
        return 1;
    }

    echo "OK\n";
    return 0;
};

$commands['session:smoke'] = function () use ($rootPath, $securityConfig, $logger): int {
    $sessionCheck = new SessionCheck($securityConfig['session'] ?? []);
    $checkResult = $sessionCheck->run();

    $savePath = $rootPath . '/storage/sessions';
    if (!is_dir($savePath)) {
        mkdir($savePath, 0775, true);
    }
    ini_set('session.save_path', $savePath);

    $factory = new SessionFactory($securityConfig['session'] ?? [], $logger, $rootPath);
    $session = $factory->create();
    $session->start();

    echo $checkResult['message'] . "\n";

    if (!$session->isStarted()) {
        echo "session smoke: FAIL\n";
        return 1;
    }

    $session->set('_smoke', 'ok');
    $value = $session->get('_smoke', null);
    $session->regenerateId(true);
    $session->clear();

    if ($value !== 'ok') {
        echo "session smoke: FAIL\n";
        return 1;
    }

    echo "session smoke: OK\n";
    return $checkResult['code'] === 2 ? 2 : 0;
};

$commands['doctor'] = function () use (&$commands, $rootPath, $appConfig, $storageConfig, $securityConfig): int {
    $runner = new PreflightRunner();
    $dbConfigured = dbEnvConfigured();
    $sessionCheck = new SessionCheck($securityConfig['session'] ?? []);
    $steps = [
        [
            'label' => 'policy:check',
            'enabled' => true,
            'run' => static function () use (&$commands): int {
                return $commands['policy:check']();
            },
        ],
        [
            'label' => 'contracts:fixtures:check',
            'enabled' => true,
            'run' => static function () use (&$commands): int {
                return $commands['contracts:fixtures:check']();
            },
        ],
        [
            'label' => 'session',
            'enabled' => true,
            'run' => static function () use ($sessionCheck): int {
                $result = $sessionCheck->run();
                echo $result['message'] . "\n";
                return $result['code'];
            },
        ],
        [
            'label' => 'phpunit',
            'enabled' => false,
            'run' => static function (): int {
                return 0;
            },
        ],
        [
            'label' => 'theme:validate',
            'enabled' => isset($commands['theme:validate']),
            'run' => static function () use (&$commands): int {
                return $commands['theme:validate']();
            },
        ],
        [
            'label' => 'db:check',
            'enabled' => $dbConfigured,
            'run' => static function () use (&$commands): int {
                return $commands['db:check']();
            },
        ],
    ];

    $result = $runner->run($steps);
    $runner->printReport($result['results']);

    $warnings = 0;
    echo "Environment hints:\n";
    echo '- PHP: ' . PHP_VERSION . "\n";

    $extensions = ['pdo_mysql', 'mbstring', 'openssl', 'json', 'fileinfo'];
    $appEnv = (string) ($appConfig['env'] ?? '');
    $isProd = strtolower($appEnv) === 'prod';
    $extStatus = [];
    foreach ($extensions as $ext) {
        $loaded = extension_loaded($ext);
        $extStatus[] = $ext . '=' . ($loaded ? 'yes' : 'no');
        if ($isProd && !$loaded && ($ext !== 'pdo_mysql' || $dbConfigured)) {
            $warnings++;
        }
    }
    echo '- Extensions: ' . implode(', ', $extStatus) . "\n";

    $storageDisk = (string) ($storageConfig['default'] ?? $storageConfig['default_raw'] ?? '');
    if ($storageDisk === '') {
        $storageDisk = envValue('STORAGE_DISK');
    }
    echo '- STORAGE_DISK: ' . $storageDisk . "\n";

    $storageChecks = [
        'storage' => $rootPath . '/storage',
        'storage/cache' => $rootPath . '/storage/cache',
        'storage/logs' => $rootPath . '/storage/logs',
    ];
    $storageStatus = [];
    foreach ($storageChecks as $label => $path) {
        $ok = is_dir($path) && is_writable($path);
        $storageStatus[] = $label . '=' . ($ok ? 'yes' : 'no');
        if (!$ok) {
            $warnings++;
        }
    }
    echo '- Storage writable: ' . implode(', ', $storageStatus) . "\n";

    $appDebug = (bool) ($appConfig['debug'] ?? false);
    $appHeadless = (bool) ($appConfig['headless_mode'] ?? false);
    echo '- APP_ENV=' . $appEnv . ' APP_DEBUG=' . ($appDebug ? 'true' : 'false')
        . ' APP_HEADLESS=' . ($appHeadless ? 'true' : 'false') . "\n";

    $trustProxy = envBool('TRUST_PROXY_ENABLED', false);
    $cspAllowCdn = envBool('CSP_ALLOW_CDN', false);
    echo '- TRUST_PROXY_ENABLED=' . ($trustProxy ? 'true' : 'false')
        . ' CSP_ALLOW_CDN=' . ($cspAllowCdn ? 'true' : 'false') . "\n";

    if ($result['code'] === 1) {
        return 1;
    }
    if ($warnings > 0 || $result['code'] === 2) {
        return 2;
    }

    return 0;
};

$commands['preflight'] = function () use (&$commands, $args, $rootPath, $securityConfig): int {
    $noTests = hasFlag($args, 'no-tests');
    $noDb = hasFlag($args, 'no-db');
    $strict = hasFlag($args, 'strict');

    $runner = new PreflightRunner();
    $sessionCheck = new SessionCheck($securityConfig['session'] ?? []);
    $steps = [
        [
            'label' => 'policy:check',
            'enabled' => true,
            'run' => static function () use (&$commands, $strict): int {
                $prev = $_ENV['POLICY_STRICT'] ?? null;
                if ($strict) {
                    $_ENV['POLICY_STRICT'] = 'true';
                }
                $code = $commands['policy:check']();
                if ($prev === null) {
                    unset($_ENV['POLICY_STRICT']);
                } else {
                    $_ENV['POLICY_STRICT'] = $prev;
                }
                return $code;
            },
        ],
        [
            'label' => 'contracts:fixtures:check',
            'enabled' => true,
            'run' => static function () use (&$commands): int {
                return $commands['contracts:fixtures:check']();
            },
        ],
        [
            'label' => 'session',
            'enabled' => true,
            'run' => static function () use ($sessionCheck): int {
                $result = $sessionCheck->run();
                echo $result['message'] . "\n";
                return $result['code'];
            },
        ],
        [
            'label' => 'phpunit',
            'enabled' => !$noTests,
            'run' => static function () use ($rootPath): int {
                $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($rootPath . '/vendor/bin/phpunit');
                passthru($cmd, $code);
                return (int) $code;
            },
        ],
        [
            'label' => 'theme:validate',
            'enabled' => isset($commands['theme:validate']),
            'run' => static function () use (&$commands): int {
                return $commands['theme:validate']();
            },
        ],
        [
            'label' => 'db:check',
            'enabled' => !$noDb,
            'run' => static function () use (&$commands): int {
                return $commands['db:check']();
            },
        ],
    ];

    $result = $runner->run($steps);
    $runner->printReport($result['results']);
    return $result['code'];
};

$commands['policy:check'] = function () use ($rootPath): int {
    require_once $rootPath . '/tools/policy-check.php';
    return policy_run([
        $rootPath . '/themes',
        $rootPath . '/src',
        $rootPath . '/modules',
    ]);
};

if ($command === '' || !isset($commands[$command])) {
    echo "Available commands:\n";
    foreach (array_keys($commands) as $name) {
        echo "  - {$name}\n";
    }
    exit(1);
}

exit($commands[$command]());

function getOption(array $args, string $name): ?string
{
    $prefix = '--' . $name . '=';
    foreach ($args as $index => $arg) {
        if (str_starts_with($arg, $prefix)) {
            return substr($arg, strlen($prefix));
        }
        if ($arg === '--' . $name) {
            return $args[$index + 1] ?? null;
        }
    }

    return null;
}

function hasFlag(array $args, string $name): bool
{
    return in_array('--' . $name, $args, true);
}

function envValue(string $key): string
{
    $value = $_ENV[$key] ?? '';
    if ($value === '' && function_exists('getenv')) {
        $envValue = getenv($key);
        if ($envValue !== false) {
            $value = (string) $envValue;
        }
    }

    return (string) $value;
}

function envBool(string $key, bool $default = false): bool
{
    $value = envValue($key);
    if ($value === '') {
        return $default;
    }

    $parsed = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    return $parsed ?? $default;
}

function dbEnvConfigured(): bool
{
    $keys = ['DB_DRIVER', 'DB_HOST', 'DB_DATABASE', 'DB_NAME', 'DB_USERNAME', 'DB_USER', 'DB_PASSWORD', 'DB_PORT'];
    foreach ($keys as $key) {
        if (trim(envValue($key)) !== '') {
            return true;
        }
    }

    return false;
}

/** @return array<int, array{contract: string, fixture: string, payload: array}> */
function collectContractFixtures(array $contracts): array
{
    $fixtures = [];
    foreach ($contracts as $spec) {
        $name = is_string($spec['name'] ?? null) ? $spec['name'] : '';
        if ($name === '') {
            continue;
        }

        $exampleOk = buildFixtureFromExample($name, $spec['example_ok'] ?? null, $name);
        if ($exampleOk !== null) {
            $fixtures[] = $exampleOk;
        }

        $exampleError = buildFixtureFromExample($name, $spec['example_error'] ?? null, $name . '.error');
        if ($exampleError !== null) {
            $fixtures[] = $exampleError;
        }
    }

    return $fixtures;
}

/** @return array{contract: string, fixture: string, payload: array}|null */
function buildFixtureFromExample(string $contractName, mixed $example, string $defaultFixture): ?array
{
    if (!is_array($example)) {
        return null;
    }

    $payload = $example['payload'] ?? null;
    if (!is_array($payload)) {
        $payload = $example;
    }
    if (!is_array($payload)) {
        return null;
    }

    $fixture = $example['fixture'] ?? $defaultFixture;
    if (!is_string($fixture) || $fixture === '') {
        $fixture = $defaultFixture;
    }

    return [
        'contract' => $contractName,
        'fixture' => $fixture,
        'payload' => $payload,
    ];
}

/** @param array<int, string> $names */
function findContractNameForFixture(string $fixtureName, array $names): ?string
{
    $match = null;
    $maxLen = -1;
    foreach ($names as $name) {
        if ($fixtureName === $name || str_starts_with($fixtureName, $name . '.')) {
            $len = strlen($name);
            if ($len > $maxLen) {
                $maxLen = $len;
                $match = $name;
            }
        }
    }

    return $match;
}

/** @return array<string, array{path: string, version: string|null}> */
function discoverModules(string $rootPath): array
{
    $modulesDir = $rootPath . '/modules';
    if (!is_dir($modulesDir)) {
        return [];
    }

    $items = scandir($modulesDir) ?: [];
    $discovered = [];
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $path = $modulesDir . '/' . $item;
        if (!is_dir($path)) {
            continue;
        }

        $name = $item;
          $version = null;
          $type = 'feature';
          $metaPath = $path . '/module.json';
          if (is_file($metaPath)) {
              $raw = (string) file_get_contents($metaPath);
              $data = json_decode($raw, true);
              if (is_array($data)) {
                  $name = is_string($data['name'] ?? null) ? $data['name'] : $name;
                  $version = is_string($data['version'] ?? null) ? $data['version'] : null;
                  $type = is_string($data['type'] ?? null) ? $data['type'] : $type;
              }
          }

          $discovered[$name] = [
              'path' => $path,
              'version' => $version,
              'type' => $type,
          ];
    }

    return $discovered;
}
