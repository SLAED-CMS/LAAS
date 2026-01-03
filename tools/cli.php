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
use Laas\Support\LoggerFactory;
use Laas\Support\OpsChecker;
use Laas\I18n\Translator;

$rootPath = dirname(__DIR__);
require $rootPath . '/vendor/autoload.php';

if (class_exists(\Dotenv\Dotenv::class)) {
    \Dotenv\Dotenv::createImmutable($rootPath)->safeLoad();
}

$command = $argv[1] ?? '';
$args = array_slice($argv, 2);

$appConfig = require $rootPath . '/config/app.php';
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
$settings = new SettingsRepository($dbManager->pdo());
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

$commands['settings:get'] = function () use ($settings, $args): int {
    $key = $args[0] ?? '';
    if ($key === '') {
        echo "Usage: settings:get KEY\n";
        return 1;
    }

    $value = $settings->get($key, null);
    if (is_array($value)) {
        echo json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
        return 0;
    }

    echo (string) $value . "\n";
    return 0;
};

$commands['settings:set'] = function () use ($settings, $args): int {
    $key = $args[0] ?? '';
    $value = $args[1] ?? null;
    $type = (string) (getOption($args, 'type') ?? 'string');

    if ($key === '' || $value === null) {
        echo "Usage: settings:set KEY VALUE [--type=string|int|bool|json]\n";
        return 1;
    }

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
