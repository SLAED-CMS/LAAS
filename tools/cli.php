<?php
declare(strict_types=1);

use Laas\Ai\FileChangeApplier;
use Laas\Ai\Plan;
use Laas\Ai\PlanRunner;
use Laas\Ai\PlanStore;
use Laas\Ai\Proposal;
use Laas\Ai\ProposalStore;
use Laas\Ai\ProposalValidator;
use Laas\Ai\Dev\DevAutopilot;
use Laas\Ai\Dev\ModuleScaffolder;
use Laas\Database\DatabaseManager;
use Laas\Database\DbIndexInspector;
use Laas\Database\Migrations\Migrator;
use Laas\Database\Migrations\MigrationSafetyAnalyzer;
use Laas\Database\Repositories\ModulesRepository;
use Laas\Database\Repositories\PermissionsRepository;
use Laas\Database\Repositories\RbacRepository;
use Laas\Database\Repositories\RolesRepository;
use Laas\Database\Repositories\SettingsRepository;
use Laas\Database\Repositories\UsersRepository;
use Laas\Database\Repositories\SecurityReportsRepository;
use Laas\Modules\Media\Repository\MediaRepository;
use Laas\Modules\Media\Service\LocalStorageDriver;
use Laas\Modules\Media\Service\MediaGcService;
use Laas\Modules\Media\Service\MediaThumbnailService;
use Laas\Modules\Media\Service\MediaUploadReaper;
use Laas\Modules\Media\Service\MediaVerifyService;
use Laas\Modules\Media\Service\S3Storage;
use Laas\Modules\Media\Service\StorageService;
use Laas\Modules\Media\Service\StorageWalker;
use Laas\Security\HtmlSanitizer;
use Laas\Support\AuditLogger;
use Laas\Support\BackupManager;
use Laas\Support\ConfigSanityChecker;
use Laas\Support\ConfigExporter;
use Laas\Support\LoggerFactory;
use Laas\Support\OpsChecker;
use Laas\Support\ReleaseChecker;
use Laas\Settings\SettingsProvider;
use Laas\Theme\ThemeValidator;
use Laas\Support\Cache\CachePruner;
use Laas\Support\Cache\CacheFactory;
use Laas\Support\PreflightRunner;
use Laas\Ops\Checks\BackupWritableCheck;
use Laas\Ops\Checks\SessionCheck;
use Laas\Ops\Checks\SecurityHeadersCheck;
use Laas\Session\SessionFactory;
use Laas\Session\Redis\RedisClient;
use Laas\I18n\Translator;
use Laas\Http\Contract\ContractRegistry;
use Laas\Http\Contract\ContractFixtureNormalizer;
use Laas\Http\Contract\ContractDump;
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
$migrator = null;
$getMigrator = static function () use (&$migrator, $dbManager, $rootPath, $modulesConfig, $appConfig, $logger): Migrator {
    if ($migrator !== null) {
        return $migrator;
    }
    $migrator = new Migrator($dbManager, $rootPath, $modulesConfig, [
        'app' => $appConfig,
        'logger' => $logger,
        'is_cli' => true,
    ], $logger);

    return $migrator;
};

$dbRepos = null;
$getDbRepos = static function () use (&$dbRepos, $dbManager): ?array {
    if ($dbRepos !== null) {
        return $dbRepos;
    }
    try {
        if (!$dbManager->healthCheck()) {
            return null;
        }
        $pdo = $dbManager->pdo();
        $dbRepos = [
            'modules' => new ModulesRepository($pdo),
            'rbac' => new RbacRepository($pdo),
            'roles' => new RolesRepository($pdo),
            'permissions' => new PermissionsRepository($pdo),
            'users' => new UsersRepository($pdo),
        ];
        return $dbRepos;
    } catch (Throwable) {
        return null;
    }
};

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

$commands['cache:prune'] = function () use ($rootPath, $appConfig): int {
    $cacheConfig = is_file($rootPath . '/config/cache.php') ? require $rootPath . '/config/cache.php' : [];
    $ttlDays = (int) ($cacheConfig['ttl_days'] ?? 7);
    $pruner = new CachePruner($rootPath . '/storage/cache');
    $result = $pruner->prune($ttlDays);

    $translator = new Translator(
        $rootPath,
        (string) ($appConfig['theme'] ?? 'default'),
        (string) ($appConfig['default_locale'] ?? 'en')
    );

    if ((int) ($result['deleted'] ?? 0) > 0) {
        echo $translator->trans('system.cache_pruned', ['count' => (int) ($result['deleted'] ?? 0)]) . "\n";
    } else {
        echo $translator->trans('system.cache_prune_none') . "\n";
    }

    return 0;
};

$commands['cache:status'] = function () use ($rootPath): int {
    $config = CacheFactory::config($rootPath);
    $enabled = (bool) ($config['enabled'] ?? true);
    $ttl = (int) ($config['ttl_default'] ?? $config['default_ttl'] ?? 300);
    $tagTtl = (int) ($config['tag_ttl'] ?? $ttl);
    $dir = $rootPath . '/storage/cache/data';
    $pruneFile = $rootPath . '/storage/cache/.prune.json';
    $lastPrune = 'n/a';
    if (is_file($pruneFile)) {
        $raw = json_decode((string) file_get_contents($pruneFile), true);
        if (is_array($raw) && isset($raw['at'])) {
            $lastPrune = gmdate('Y-m-d\\TH:i:s\\Z', (int) $raw['at']);
        }
    }

    $hits = 'n/a';
    $misses = 'n/a';

    echo 'enabled: ' . ($enabled ? 'true' : 'false') . "\n";
    echo 'default_ttl: ' . $ttl . "\n";
    echo 'tag_ttl: ' . $tagTtl . "\n";
    echo 'cache_dir: ' . $dir . "\n";
    echo 'last_prune: ' . $lastPrune . "\n";
    echo 'stats: hits=' . $hits . ' misses=' . $misses . "\n";
    return 0;
};

$commands['security:reports:prune'] = function () use ($rootPath, $dbManager, $appConfig, $args): int {
    $days = (int) (getOption($args, 'days') ?? 14);
    if ($days <= 0) {
        $days = 14;
    }

    if (!$dbManager->healthCheck()) {
        echo "DB not available.\n";
        return 1;
    }

    $repo = new SecurityReportsRepository($dbManager);
    $deleted = $repo->prune($days);

    $translator = new Translator(
        $rootPath,
        (string) ($appConfig['theme'] ?? 'default'),
        (string) ($appConfig['default_locale'] ?? 'en')
    );
    echo $translator->trans('security.reports_pruned', ['count' => $deleted, 'days' => $days]) . "\n";

    return 0;
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

$commands['templates:raw:scan'] = function () use ($rootPath, $args): int {
    $pathArg = (string) (getOption($args, 'path') ?? 'themes');
    $json = hasFlag($args, 'json');

    $scan = scanTemplateRaw($rootPath, $pathArg, ['html', 'tpl']);
    if ($scan === null) {
        echo "Path not found: {$pathArg}\n";
        return 1;
    }

    $filesScanned = (int) ($scan['files_scanned'] ?? 0);
    $items = $scan['items'] ?? [];
    $hits = is_array($items) ? count($items) : 0;

    if ($json) {
        echo json_encode([
            'files_scanned' => $filesScanned,
            'hits' => $hits,
            'items' => $items,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
        return 0;
    }

    if ($items !== []) {
        $grouped = [];
        foreach ($items as $item) {
            $grouped[$item['file']][] = $item;
        }
        foreach ($grouped as $file => $fileItems) {
            echo $file . "\n";
            foreach ($fileItems as $item) {
                echo '  line ' . $item['line'] . ' ' . $item['kind'] . ': ' . $item['excerpt'] . "\n";
            }
        }
    }
    echo 'files_scanned=' . $filesScanned . ' hits=' . $hits . "\n";
    return 0;
};

$commands['templates:raw:check'] = function () use ($rootPath, $args, $securityConfig): int {
    $pathArg = (string) (getOption($args, 'path') ?? 'themes');
    $defaultAllowlist = (string) ($securityConfig['template_raw_allowlist_path'] ?? 'config/template_raw_allowlist.php');
    $allowlistArg = (string) (getOption($args, 'allowlist') ?? $defaultAllowlist);
    $update = hasFlag($args, 'update');

    $scan = scanTemplateRaw($rootPath, $pathArg, ['html', 'tpl']);
    if ($scan === null) {
        echo "Path not found: {$pathArg}\n";
        return 1;
    }

    $scanItems = [];
    foreach ($scan['items'] ?? [] as $item) {
        $file = (string) ($item['file'] ?? '');
        $line = (int) ($item['line'] ?? 0);
        $kind = (string) ($item['kind'] ?? '');
        if ($file === '' || $line <= 0 || $kind === '') {
            continue;
        }
        $scanItems[] = [
            'file' => $file,
            'line' => $line,
            'kind' => $kind,
        ];
    }

    $allowlistPath = isAbsolutePath($allowlistArg)
        ? $allowlistArg
        : $rootPath . '/' . ltrim($allowlistArg, '/\\');

    if ($update) {
        $dir = dirname($allowlistPath);
        if (!is_dir($dir)) {
            echo "Allowlist directory not found: {$dir}\n";
            return 1;
        }
        usort($scanItems, static function (array $a, array $b): int {
            $cmp = strcmp($a['file'], $b['file']);
            if ($cmp !== 0) {
                return $cmp;
            }
            $cmp = $a['line'] <=> $b['line'];
            if ($cmp !== 0) {
                return $cmp;
            }
            return strcmp($a['kind'], $b['kind']);
        });

        $lines = [
            '<?php',
            '',
            'declare(strict_types=1);',
            '',
            '/**',
            ' * Approved raw usages in themes.',
            ' *',
            ' * This allowlist documents intentionally unescaped template outputs.',
            ' * Each entry represents a {% raw %} usage that has been security-reviewed.',
            ' *',
            ' * Update via: php tools/cli.php templates:raw:check --update',
            ' */',
            'return [',
            "    'version' => 1,",
            "    'items' => [",
        ];
        foreach ($scanItems as $item) {
            $lines[] = sprintf(
                "        ['file' => %s, 'line' => %d, 'kind' => %s],",
                var_export($item['file'], true),
                $item['line'],
                var_export($item['kind'], true)
            );
        }
        $lines[] = '    ],';
        $lines[] = '];';
        $lines[] = '';
        file_put_contents($allowlistPath, implode("\n", $lines));
        echo 'allowlist updated: items=' . count($scanItems) . "\n";
        return 0;
    }

    if (!is_file($allowlistPath)) {
        echo "Allowlist not found: {$allowlistArg}\n";
        return 1;
    }

    $allowlistData = require $allowlistPath;
    if (!is_array($allowlistData)) {
        echo "Allowlist invalid: {$allowlistArg}\n";
        return 1;
    }
    $allowlistItems = is_array($allowlistData['items'] ?? null) ? $allowlistData['items'] : [];

    $scanCounts = [];
    $scanTotal = 0;
    foreach ($scanItems as $item) {
        $key = $item['file'] . '|' . $item['line'] . '|' . $item['kind'];
        if (!isset($scanCounts[$key])) {
            $scanCounts[$key] = [
                'item' => $item,
                'count' => 0,
            ];
        }
        $scanCounts[$key]['count']++;
        $scanTotal++;
    }

    $allowCounts = [];
    $allowTotal = 0;
    foreach ($allowlistItems as $item) {
        if (!is_array($item)) {
            continue;
        }
        $file = (string) ($item['file'] ?? '');
        $line = (int) ($item['line'] ?? 0);
        $kind = (string) ($item['kind'] ?? '');
        if ($file === '' || $line <= 0 || $kind === '') {
            continue;
        }
        $key = $file . '|' . $line . '|' . $kind;
        if (!isset($allowCounts[$key])) {
            $allowCounts[$key] = [
                'item' => [
                    'file' => $file,
                    'line' => $line,
                    'kind' => $kind,
                ],
                'count' => 0,
            ];
        }
        $allowCounts[$key]['count']++;
        $allowTotal++;
    }

    $newItems = [];
    $missingItems = [];
    $newTotal = 0;
    $missingTotal = 0;

    foreach ($scanCounts as $key => $data) {
        $allowCount = $allowCounts[$key]['count'] ?? 0;
        if ($data['count'] > $allowCount) {
            $extra = $data['count'] - $allowCount;
            $newItems[] = [
                'item' => $data['item'],
                'count' => $extra,
            ];
            $newTotal += $extra;
        }
    }

    foreach ($allowCounts as $key => $data) {
        $scanCount = $scanCounts[$key]['count'] ?? 0;
        if ($data['count'] > $scanCount) {
            $missing = $data['count'] - $scanCount;
            $missingItems[] = [
                'item' => $data['item'],
                'count' => $missing,
            ];
            $missingTotal += $missing;
        }
    }

    echo 'hits=' . $scanTotal . ' allowlist=' . $allowTotal . ' new=' . $newTotal . ' missing=' . $missingTotal . "\n";
    foreach ($newItems as $entry) {
        $item = $entry['item'];
        $count = (int) ($entry['count'] ?? 0);
        $suffix = $count > 1 ? ' x' . $count : '';
        echo 'new: ' . $item['file'] . ':' . $item['line'] . ' ' . $item['kind'] . $suffix . "\n";
    }
    foreach ($missingItems as $entry) {
        $item = $entry['item'];
        $count = (int) ($entry['count'] ?? 0);
        $suffix = $count > 1 ? ' x' . $count : '';
        echo 'missing: ' . $item['file'] . ':' . $item['line'] . ' ' . $item['kind'] . $suffix . "\n";
    }

    return $newTotal > 0 ? 3 : 0;
};

$commands['ai:proposal:demo'] = function () use ($rootPath): int {
    $id = bin2hex(random_bytes(16));
    $proposal = new Proposal([
        'id' => $id,
        'created_at' => gmdate(DATE_ATOM),
        'kind' => 'demo',
        'summary' => 'Demo proposal scaffold',
        'file_changes' => [
            [
                'op' => 'create',
                'path' => 'modules/Demo/README.md',
                'content' => "# Demo\n",
            ],
        ],
        'entity_changes' => [],
        'warnings' => ['demo only'],
        'confidence' => 0.5,
        'risk' => 'low',
    ]);

    $store = new ProposalStore($rootPath);
    $path = $store->save($proposal);

    $relative = normalizePath($path);
    $rootNorm = rtrim(normalizePath($rootPath), '/');
    if (str_starts_with(strtolower($relative), strtolower($rootNorm . '/'))) {
        $relative = substr($relative, strlen($rootNorm) + 1);
    }

    echo 'proposal_saved=' . $relative . ' id=' . $id . "\n";
    return 0;
};

$commands['ai:doctor'] = function () use ($rootPath, $dbConfig, $dbManager, $args): int {
    $fix = hasFlag($args, 'fix');
    echo "Runtime:\n";
    echo '- php_version=' . PHP_VERSION . "\n";
    echo '- os=' . PHP_OS_FAMILY . "\n";
    echo '- cwd=' . $rootPath . "\n";

    echo "Storage:\n";
    $storageDirs = [
        'storage/proposals' => $rootPath . '/storage/proposals',
        'storage/plans' => $rootPath . '/storage/plans',
        'storage/sandbox' => $rootPath . '/storage/sandbox',
    ];
    $created = [];
    if ($fix) {
        foreach ($storageDirs as $label => $path) {
            if (!is_dir($path)) {
                if (@mkdir($path, 0775, true) || is_dir($path)) {
                    $created[] = $label;
                }
            }
        }
    }

    $storageDirs = ['storage' => $rootPath . '/storage'] + $storageDirs;
    foreach ($storageDirs as $label => $path) {
        $exists = is_dir($path);
        $writable = $exists && is_writable($path);
        echo '- ' . $label . ' exists=' . ($exists ? 'yes' : 'no') . ' writable=' . ($writable ? 'yes' : 'no') . "\n";
    }
    if ($fix) {
        if ($created === []) {
            echo "fix: created=0\n";
        } else {
            foreach ($created as $label) {
                echo 'fix: created=' . $label . "\n";
            }
        }
    }

    echo "DB:\n";
    $driver = strtolower((string) ($dbConfig['driver'] ?? ''));
    $dbName = (string) ($dbConfig['database'] ?? '');
    echo 'db_driver=' . ($driver !== '' ? $driver : 'unknown') . "\n";

    if (in_array($driver, ['mysql', 'mariadb'], true)) {
        $host = trim((string) ($dbConfig['host'] ?? ''));
        $dbName = trim((string) ($dbConfig['database'] ?? ''));
        $configPresent = $host !== '' && $dbName !== '' && $dbName !== ':memory:';

        if (!$configPresent) {
            echo "db_ping=skip reason=missing_config\n";
        } else {
            $ping = 'fail';
            $error = '';
            try {
                $stmt = $dbManager->pdo()->query('SELECT 1');
                if ($stmt !== false) {
                    $ping = 'ok';
                }
            } catch (Throwable $e) {
                $error = trim(str_replace(["\r", "\n"], ' ', $e->getMessage()));
                if (strlen($error) > 200) {
                    $error = substr($error, 0, 200);
                }
            }

            echo 'db_ping=' . $ping;
            if ($ping !== 'ok' && $error !== '') {
                echo ' error=' . $error;
            }
            echo "\n";
        }
    } elseif ($driver === 'sqlite' && $dbName === ':memory:') {
        echo "db_hint=sqlite_memory\n";
    }

    echo "AI artifacts:\n";
    $proposalFiles = glob($rootPath . '/storage/proposals/*.json') ?: [];
    $planFiles = glob($rootPath . '/storage/plans/*.json') ?: [];
    echo 'proposals=' . count($proposalFiles) . "\n";
    echo 'plans=' . count($planFiles) . "\n";

    return 0;
};

$commands['ai:plan:demo'] = function () use ($rootPath): int {
    $id = bin2hex(random_bytes(16));
    $plan = new Plan([
        'id' => $id,
        'created_at' => gmdate(DATE_ATOM),
        'kind' => 'demo',
        'summary' => 'Demo safety plan',
        'steps' => [
            [
                'id' => 's1',
                'title' => 'Templates raw check',
                'command' => 'templates:raw:check',
                'args' => ['--path=themes'],
            ],
            [
                'id' => 's2',
                'title' => 'Policy check',
                'command' => 'policy:check',
                'args' => [],
            ],
        ],
        'confidence' => 0.6,
        'risk' => 'low',
    ]);

    $store = new PlanStore($rootPath);
    $path = $store->save($plan);

    $relative = normalizePath($path);
    $rootNorm = rtrim(normalizePath($rootPath), '/');
    if (str_starts_with(strtolower($relative), strtolower($rootNorm . '/'))) {
        $relative = substr($relative, strlen($rootNorm) + 1);
    }

    echo 'plan_saved=' . $relative . ' id=' . $id . "\n";
    return 0;
};

$commands['ai:plan:run'] = function () use ($rootPath, $args): int {
    $id = (string) ($args[0] ?? '');
    if ($id === '') {
        echo "Usage: ai:plan:run <id> [--dry-run] [--yes]\n";
        return 1;
    }

    $dryRun = parseBoolOption($args, 'dry-run', true);
    $dryRunExplicit = getOption($args, 'dry-run') !== null || hasFlag($args, 'dry-run');
    $confirmed = hasFlag($args, 'yes');
    if ($confirmed && !$dryRunExplicit) {
        $dryRun = false;
    }

    try {
        $plan = (new PlanStore($rootPath))->load($id);
    } catch (Throwable $e) {
        echo "Plan not found or invalid: {$id}\n";
        return 1;
    }

    $runner = new PlanRunner($rootPath);
    $result = $runner->run($plan, $dryRun, $confirmed);

    $mode = $dryRun ? 'dry-run' : 'execute';
    if (!empty($result['refused'])) {
        echo "refusing to run plan without --yes\n";
        echo 'id=' . $id . ' mode=' . $mode
            . ' steps_total=' . (int) ($result['steps_total'] ?? 0)
            . ' steps_run=' . (int) ($result['steps_run'] ?? 0)
            . ' failed=' . (int) ($result['failed'] ?? 0) . "\n";
        return 2;
    }

    echo 'id=' . $id . ' mode=' . $mode
        . ' steps_total=' . (int) ($result['steps_total'] ?? 0)
        . ' steps_run=' . (int) ($result['steps_run'] ?? 0)
        . ' failed=' . (int) ($result['failed'] ?? 0) . "\n";

    foreach ($result['outputs'] ?? [] as $output) {
        if (!is_array($output)) {
            continue;
        }
        $stepId = (string) ($output['id'] ?? '');
        $status = (string) ($output['status'] ?? '');
        $command = (string) ($output['command'] ?? '');
        $exit = $output['exit_code'] ?? null;
        $argsList = $output['args'] ?? [];
        $argsText = is_array($argsList) ? json_encode($argsList, JSON_UNESCAPED_SLASHES) : '[]';

        echo 'step=' . $stepId . ' status=' . $status . ' command=' . $command . ' args=' . $argsText;
        if (is_int($exit)) {
            echo ' exit=' . $exit;
        }
        echo "\n";

        $stdout = (string) ($output['stdout'] ?? '');
        $stderr = (string) ($output['stderr'] ?? '');
        if ($stdout !== '') {
            echo "stdout: " . $stdout . "\n";
        }
        if ($stderr !== '') {
            echo "stderr: " . $stderr . "\n";
        }
    }

    return ((int) ($result['failed'] ?? 0)) > 0 ? 3 : 0;
};

$commands['ai:proposal:apply'] = function () use ($rootPath, $args): int {
    $id = (string) ($args[0] ?? '');
    if ($id === '') {
        echo "Usage: ai:proposal:apply <id> [--dry-run] [--yes] [--base-dir=<path>]\n";
        return 1;
    }

    $dryRun = parseBoolOption($args, 'dry-run', true);
    $dryRunExplicit = getOption($args, 'dry-run') !== null || hasFlag($args, 'dry-run');
    $confirmed = hasFlag($args, 'yes');
    if ($confirmed && !$dryRunExplicit) {
        $dryRun = false;
    }
    $baseDir = getOption($args, 'base-dir');
    if (is_string($baseDir)) {
        $baseDir = trim($baseDir);
        if ($baseDir === '') {
            $baseDir = null;
        }
    }
    if (is_string($baseDir) && $baseDir !== '' && !isAbsolutePath($baseDir)) {
        $baseDir = $rootPath . '/' . ltrim($baseDir, '/\\');
    }

    if (!$dryRun && !$confirmed) {
        echo "refusing to apply changes without --yes\n";
        echo 'id=' . $id . ' mode=apply would_apply=0 applied=0 errors=0' . "\n";
        return 2;
    }

    try {
        $proposal = (new ProposalStore($rootPath))->load($id);
    } catch (Throwable $e) {
        echo "Proposal not found or invalid: {$id}\n";
        return 1;
    }

    $data = $proposal->toArray();
    $fileChanges = is_array($data['file_changes'] ?? null) ? $data['file_changes'] : [];
    $applier = new FileChangeApplier($baseDir !== null ? $baseDir : $rootPath);
    $summary = $applier->apply($fileChanges, $dryRun, $confirmed);

    $mode = $dryRun ? 'dry-run' : 'apply';
    $wouldApply = (int) ($summary['would_apply'] ?? 0);
    $applied = (int) ($summary['applied'] ?? 0);
    $errors = (int) ($summary['errors'] ?? 0);

    echo 'id=' . $id . ' mode=' . $mode
        . ' would_apply=' . $wouldApply
        . ' applied=' . $applied
        . ' errors=' . $errors . "\n";

    return $errors > 0 ? 3 : 0;
};

$commands['ai:proposal:validate'] = function () use ($rootPath, $args): int {
    $target = (string) ($args[0] ?? '');
    if ($target === '') {
        echo "Usage: ai:proposal:validate <id-or-path> [--json]\n";
        return 1;
    }

    $json = hasFlag($args, 'json');
    $path = $target;
    if (!isAbsolutePath($path)) {
        $path = $rootPath . '/' . ltrim($path, '/\\');
    }

    $data = null;
    if (is_file($path)) {
        $raw = (string) file_get_contents($path);
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            $errors = [
                ['path' => 'json', 'message' => 'invalid JSON'],
            ];
            return outputProposalValidation($errors, $json);
        }
        $data = $decoded;
    } else {
        try {
            $proposal = (new ProposalStore($rootPath))->load($target);
            $data = $proposal->toArray();
        } catch (Throwable $e) {
            echo "Proposal not found or invalid: {$target}\n";
            return 1;
        }
    }

    $validator = new ProposalValidator();
    $errors = $validator->validate($data);
    return outputProposalValidation($errors, $json);
};

$commands['ai:dev:module:scaffold-and-check'] = function () use ($rootPath, $args): int {
    $name = (string) ($args[0] ?? '');
    if ($name === '') {
        echo "Usage: ai:dev:module:scaffold-and-check <ModuleName> [--sandbox=1|0] [--api-envelope=1|0] [--yes]\n";
        return 1;
    }

    $sandbox = parseBoolOption($args, 'sandbox', true);
    $apiEnvelope = parseBoolOption($args, 'api-envelope', true);
    $yes = hasFlag($args, 'yes');

    $autopilot = new DevAutopilot($rootPath);
    $result = $autopilot->run($name, $sandbox, $apiEnvelope, $yes);

    echo 'mode=' . (string) ($result['mode'] ?? '') . "\n";
    echo 'module=' . $name . "\n";
    echo 'module_path=' . (string) ($result['module_path'] ?? '') . "\n";
    echo 'sandbox=' . ($sandbox ? 'on' : 'off') . ' envelope=' . ($apiEnvelope ? 'on' : 'off') . "\n";
    echo 'proposal_id=' . (string) ($result['proposal_id'] ?? '') . ' proposal_valid=' . (int) ($result['proposal_valid'] ?? 0) . "\n";
    echo 'applied=' . (int) ($result['applied'] ?? 0) . ' errors=' . (int) ($result['errors'] ?? 0) . "\n";
    echo 'plan_id=' . (string) ($result['plan_id'] ?? '') . ' plan_failed=' . (int) ($result['plan_failed'] ?? 0) . "\n";

    if (!$yes) {
        echo 'hint=use --yes to apply scaffold and run checks' . "\n";
    }

    if ((int) ($result['proposal_valid'] ?? 0) !== 1) {
        return 3;
    }
    if ((int) ($result['errors'] ?? 0) > 0 || (int) ($result['plan_failed'] ?? 0) > 0) {
        return 3;
    }

    return 0;
};

$commands['ai:dev:module:scaffold'] = function () use ($rootPath, $args): int {
    $name = (string) ($args[0] ?? '');
    if ($name === '') {
        echo "Usage: ai:dev:module:scaffold <ModuleName> [--id=<id>] [--dry-run] [--api-envelope=1|0] [--sandbox=1|0]\n";
        return 1;
    }

    $customId = getOption($args, 'id');
    $dryRun = hasFlag($args, 'dry-run');
    $apiEnvelope = parseBoolOption($args, 'api-envelope', true);
    $sandbox = parseBoolOption($args, 'sandbox', true);

    try {
        $scaffolder = new ModuleScaffolder();
        $proposal = $scaffolder->scaffold($name, $apiEnvelope, $sandbox);
    } catch (Throwable $e) {
        echo "Invalid module name.\n";
        return 1;
    }

    if (is_string($customId) && $customId !== '') {
        $payload = $proposal->toArray();
        $payload['id'] = $customId;
        $payload['created_at'] = gmdate(DATE_ATOM);
        $proposal = Proposal::fromArray($payload);
    }

    $store = new ProposalStore($rootPath);
    $path = $store->save($proposal);

    $relative = normalizePath($path);
    $rootNorm = rtrim(normalizePath($rootPath), '/');
    if (str_starts_with(strtolower($relative), strtolower($rootNorm . '/'))) {
        $relative = substr($relative, strlen($rootNorm) + 1);
    }

    $id = (string) ($proposal->toArray()['id'] ?? '');
    $mode = $dryRun ? 'dry-run' : 'saved';
    $envelope = $apiEnvelope ? 'on' : 'off';
    $sandboxLabel = $sandbox ? 'on' : 'off';
    echo 'proposal_saved=' . $relative . ' id=' . $id . ' module=' . $name . ' mode=' . $mode . ' envelope=' . $envelope . ' sandbox=' . $sandboxLabel . "\n";
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

$commands['content:sanitize-pages'] = function () use ($dbManager, $args): int {
    if (!$dbManager->healthCheck()) {
        echo "DB not available.\n";
        return 1;
    }

    $dryRun = hasFlag($args, 'dry-run');
    $confirmed = hasFlag($args, 'yes');
    $limit = (int) (getOption($args, 'limit') ?? 0);
    if ($limit < 0) {
        $limit = 0;
    }
    $offset = (int) (getOption($args, 'offset') ?? 0);
    if ($offset < 0) {
        $offset = 0;
    }

    if (!$dryRun && !$confirmed) {
        echo "refusing to apply updates without --yes\n";
        echo "scanned=0 changed=0 updated=0\n";
        return 2;
    }

    $pdo = $dbManager->pdo();
    $sql = 'SELECT id, content FROM pages ORDER BY id ASC';
    if ($limit > 0) {
        $sql .= ' LIMIT :limit OFFSET :offset';
    }
    $stmt = $pdo->prepare($sql);
    if ($limit > 0) {
        $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, \PDO::PARAM_INT);
    }
    $stmt->execute();

    $updateStmt = $pdo->prepare('UPDATE pages SET content = :content WHERE id = :id');
    $sanitizer = new HtmlSanitizer();

    $scanned = 0;
    $changed = 0;
    $updated = 0;

    while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
        $scanned++;
        $id = (int) ($row['id'] ?? 0);
        $content = (string) ($row['content'] ?? '');
        $sanitized = $sanitizer->sanitize($content);
        if ($sanitized === $content) {
            continue;
        }

        $changed++;
        if ($dryRun) {
            continue;
        }

        $updateStmt->execute([
            'content' => $sanitized,
            'id' => $id,
        ]);
        $updated++;
    }

    $mode = $dryRun ? 'dry-run' : 'applied';
    echo 'scanned=' . $scanned . ' changed=' . $changed . ' updated=' . $updated . ' mode=' . $mode . "\n";
    return 0;
};

$commands['db:indexes:audit'] = function () use ($dbManager, $args): int {
    try {
        if (!$dbManager->healthCheck()) {
            echo "DB not available.\n";
            return 1;
        }
        $inspector = new DbIndexInspector($dbManager->pdo());
        $result = $inspector->auditRequired();
        $json = hasFlag($args, 'json');

        if ($json) {
            echo json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
            return $result['ok'] ? 0 : 2;
        }

        if ($result['ok']) {
            echo "db.indexes.ok\n";
            return 0;
        }
        echo "db.indexes.missing\n";
        foreach ($result['missing'] as $item) {
            $table = (string) ($item['table'] ?? '');
            $column = (string) ($item['column'] ?? '');
            $type = (string) ($item['type'] ?? '');
            echo "- {$table} {$column} {$type}\n";
        }
        return 2;
    } catch (Throwable $e) {
        echo "DB index audit failed: " . $e->getMessage() . "\n";
        return 1;
    }
};

$commands['migrate:status'] = function () use ($getMigrator): int {
    try {
        $migrator = $getMigrator();
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

$commands['db:migrations:analyze'] = function () use ($getMigrator, $dbConfig, $appConfig): int {
    try {
        $safeMode = resolveMigrationSafeMode($dbConfig, $appConfig);
        $migrator = $getMigrator();
        $pending = resolvePendingMigrations($migrator, 0);
        $analyzer = new MigrationSafetyAnalyzer();
        $issues = $analyzer->analyzeAll($pending);

        $payload = [
            'safe' => $issues === [],
            'mode' => $safeMode,
            'issues' => $issues,
        ];

        echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
        return 0;
    } catch (Throwable $e) {
        echo "Migrations analyze failed: " . $e->getMessage() . "\n";
        return 1;
    }
};

$commands['migrate:up'] = function () use ($getMigrator, $args, $dbConfig, $appConfig, $rootPath): int {
    try {
        $steps = (int) (getOption($args, 'steps') ?? 0);
        $safeMode = resolveMigrationSafeMode($dbConfig, $appConfig);
        $allowOverride = (bool) ($dbConfig['migrations']['allow_destructive'] ?? false)
            && hasFlag($args, 'i-know-what-i-am-doing');

        if ($safeMode !== 'off') {
            $migrator = $getMigrator();
            $pending = resolvePendingMigrations($migrator, $steps);
            if ($pending !== []) {
                $analyzer = new MigrationSafetyAnalyzer();
                $issues = $analyzer->analyzeAll($pending);
                if ($issues !== []) {
                    if ($safeMode === 'block' && !$allowOverride) {
                        if (isProdEnv($appConfig)) {
                            $translator = new Translator(
                                $rootPath,
                                (string) ($appConfig['theme'] ?? 'default'),
                                (string) ($appConfig['default_locale'] ?? 'en')
                            );
                            echo $translator->trans('db.migrations.blocked') . "\n";
                            return 2;
                        }
                        echo "Migrations blocked by safe mode.\n";
                        return 2;
                    }
                    if ($safeMode === 'warn' && !$allowOverride) {
                        echo "WARN: destructive migrations detected.\n";
                    }
                }
            }
        }

        $migrator = $getMigrator();
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

$commands['migrate:down'] = function () use ($getMigrator, $args): int {
    try {
        $steps = (int) (getOption($args, 'steps') ?? 1);
        $migrator = $getMigrator();
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

$commands['migrate:refresh'] = function () use ($getMigrator): int {
    try {
        $migrator = $getMigrator();
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

$commands['module:status'] = function () use ($getDbRepos, $modulesConfig, $rootPath): int {
    $discovered = discoverModules($rootPath);
    $enabledConfig = [];
    foreach ($modulesConfig as $class) {
        $parts = explode('\\', trim($class, '\\'));
        $enabledConfig[] = $parts[2] ?? '';
    }

    $source = 'CONFIG';
    $enabled = $enabledConfig;

    $repos = $getDbRepos();
    $modulesRepo = $repos['modules'] ?? null;
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

$commands['module:sync'] = function () use ($getDbRepos, $modulesConfig, $rootPath): int {
    $repos = $getDbRepos();
    $modulesRepo = $repos['modules'] ?? null;
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

$commands['module:enable'] = function () use ($getDbRepos, $args, $rootPath): int {
    $repos = $getDbRepos();
    $modulesRepo = $repos['modules'] ?? null;
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

$commands['module:disable'] = function () use ($getDbRepos, $args, $rootPath): int {
    $repos = $getDbRepos();
    $modulesRepo = $repos['modules'] ?? null;
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

$commands['rbac:status'] = function () use ($getDbRepos): int {
    $repos = $getDbRepos();
    $rolesRepo = $repos['roles'] ?? null;
    $permissionsRepo = $repos['permissions'] ?? null;
    $usersRepo = $repos['users'] ?? null;
    $rbacRepo = $repos['rbac'] ?? null;
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

$commands['rbac:grant'] = function () use ($getDbRepos, $args): int {
    $repos = $getDbRepos();
    $rolesRepo = $repos['roles'] ?? null;
    $permissionsRepo = $repos['permissions'] ?? null;
    $usersRepo = $repos['users'] ?? null;
    $rbacRepo = $repos['rbac'] ?? null;
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

$commands['rbac:revoke'] = function () use ($getDbRepos, $args): int {
    $repos = $getDbRepos();
    $usersRepo = $repos['users'] ?? null;
    $rbacRepo = $repos['rbac'] ?? null;
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

$commands['media:gc'] = function () use ($dbManager, $rootPath, $storageConfig, $args): int {
    $mediaConfig = is_file($rootPath . '/config/media.php') ? require $rootPath . '/config/media.php' : [];
    $mediaConfig = is_array($mediaConfig) ? $mediaConfig : [];

    if (!(bool) ($mediaConfig['gc_enabled'] ?? true)) {
        echo "Media GC disabled.\n";
        return 1;
    }

    if (!$dbManager->healthCheck()) {
        echo "DB not available.\n";
        return 1;
    }

    $disk = (string) (getOption($args, 'disk') ?? ($storageConfig['default'] ?? 'local'));
    $error = '';
    $driver = buildStorageDriver($rootPath, $storageConfig, $disk, $error);
    if ($driver === null) {
        echo "Storage not available.\n";
        return 1;
    }

    $mode = strtolower((string) (getOption($args, 'mode') ?? 'all'));
    $dryRunDefault = (bool) ($mediaConfig['gc_dry_run_default'] ?? true);
    $dryRun = parseBoolOption($args, 'dry-run', $dryRunDefault);

    $limit = (int) ($mediaConfig['gc_max_delete_per_run'] ?? 500);
    if ($limit < 0) {
        $limit = 0;
    }
    $limitRaw = getOption($args, 'limit');
    if ($limitRaw !== null && is_numeric($limitRaw)) {
        $limitParsed = (int) $limitRaw;
        if ($limitParsed < 0) {
            $limitParsed = 0;
        }
        if ($limit > 0 && $limitParsed > 0) {
            $limit = min($limitParsed, $limit);
        } else {
            $limit = $limitParsed;
        }
    }

    $repo = new MediaRepository($dbManager);
    $walker = new StorageWalker($rootPath, $driver);
    $service = new MediaGcService($repo, $driver, $walker, $mediaConfig, new AuditLogger($dbManager));
    $result = $service->run([
        'mode' => $mode,
        'dry_run' => $dryRun,
        'limit' => $limit,
        'scan_prefix' => 'uploads/',
        'disk' => $disk,
    ]);

    echo 'disk: ' . (string) ($result['disk'] ?? '') . "\n";
    echo 'dry_run: ' . ((bool) ($result['dry_run'] ?? true) ? 'true' : 'false') . "\n";
    echo 'mode: ' . (string) ($result['mode'] ?? '') . "\n";
    echo 'limit: ' . (int) ($result['limit'] ?? 0) . "\n";
    echo 'scanned_db: ' . (int) ($result['scanned_db'] ?? 0) . "\n";
    echo 'scanned_storage: ' . (int) ($result['scanned_storage'] ?? 0) . "\n";
    echo 'orphans_found: ' . (int) ($result['orphans_found'] ?? 0) . "\n";
    echo 'deleted_count: ' . (int) ($result['deleted_count'] ?? 0) . "\n";
    echo 'bytes_freed_estimate: ' . (int) ($result['bytes_freed_estimate'] ?? 0) . "\n";
    if (!($result['ok'] ?? false)) {
        echo 'error: ' . (string) ($result['error'] ?? 'unknown') . "\n";
    }

    return ($result['ok'] ?? false) ? 0 : 2;
};

$commands['media:uploads:reap'] = function () use ($dbManager, $rootPath, $args): int {
    if (!$dbManager->healthCheck()) {
        echo "DB not available.\n";
        return 1;
    }

    $olderThanRaw = (string) (getOption($args, 'older-than') ?? '30m');
    $olderThanSeconds = parseDurationSeconds($olderThanRaw);
    if ($olderThanSeconds === null) {
        echo "Invalid --older-than value: {$olderThanRaw}\n";
        echo "Use e.g. 30m, 1h, 1d, 3600\n";
        return 1;
    }

    $limit = (int) (getOption($args, 'limit') ?? 0);
    if ($limit < 0) {
        $limit = 0;
    }

    $repo = new MediaRepository($dbManager);
    $storage = new StorageService($rootPath);
    $reaper = new MediaUploadReaper($repo, $storage);
    $result = $reaper->reap($olderThanSeconds, $limit);

    echo 'older_than: ' . $olderThanRaw . "\n";
    echo 'cutoff: ' . (string) ($result['cutoff'] ?? '') . "\n";
    echo 'limit: ' . (int) ($result['limit'] ?? 0) . "\n";
    echo 'scanned: ' . (int) ($result['scanned'] ?? 0) . "\n";
    echo 'deleted: ' . (int) ($result['deleted'] ?? 0) . "\n";
    echo 'quarantine_deleted: ' . (int) ($result['quarantine_deleted'] ?? 0) . "\n";
    echo 'disk_deleted: ' . (int) ($result['disk_deleted'] ?? 0) . "\n";
    echo 'errors: ' . (int) ($result['errors'] ?? 0) . "\n";

    return ($result['ok'] ?? false) ? 0 : 2;
};

$commands['media:sha256:dedup:report'] = function () use ($dbManager, $storageConfig, $args): int {
    if (!$dbManager->healthCheck()) {
        echo "DB not available.\n";
        return 1;
    }

    $diskFilter = getOption($args, 'disk');
    if (is_string($diskFilter)) {
        $diskFilter = trim($diskFilter);
        if ($diskFilter === '') {
            $diskFilter = null;
        }
    }

    $limit = (int) (getOption($args, 'limit') ?? 100);
    if ($limit <= 0) {
        $limit = 100;
    }

    $withPaths = hasFlag($args, 'with-paths');
    $json = hasFlag($args, 'json');

    $repo = new MediaRepository($dbManager);
    $hasDisk = $repo->hasDiskColumn();
    $diskRequested = $diskFilter !== null;
    $diskFilterApplied = $diskRequested && $hasDisk;
    $exitCode = 0;
    if ($diskRequested && !$hasDisk) {
        fwrite(STDERR, "Option --disk ignored: column media_files.disk not present\n");
        $diskFilter = null;
        $exitCode = 2;
    }

    $groups = $repo->listSha256DuplicatesReport($limit, $diskFilterApplied ? $diskFilter : null);
    $defaultDisk = (string) ($storageConfig['default'] ?? $storageConfig['default_raw'] ?? '');

    if ($json) {
        $meta = [
            'disk_supported' => $hasDisk,
            'disk_filter_applied' => $diskFilterApplied,
            'limit' => $limit,
        ];
        $payload = [];
        foreach ($groups as $group) {
            $items = [];
            foreach ($group['items'] as $item) {
                $disk = (string) ($item['disk'] ?? '');
                if ($disk === '' && $defaultDisk !== '') {
                    $disk = $defaultDisk;
                }
                $entry = [
                    'id' => (int) ($item['id'] ?? 0),
                    'disk' => $disk,
                    'size' => (int) ($item['size_bytes'] ?? 0),
                    'created_at' => (string) ($item['created_at'] ?? ''),
                    'status' => (string) ($item['status'] ?? ''),
                ];
                if ($withPaths) {
                    $entry['path'] = (string) ($item['disk_path'] ?? '');
                }
                $items[] = $entry;
            }
            $payload[] = [
                'sha256' => (string) ($group['sha256'] ?? ''),
                'count' => (int) ($group['count'] ?? 0),
                'items' => $items,
            ];
        }

        echo json_encode([
            'meta' => $meta,
            'groups' => $payload,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
        return $exitCode;
    }

    if ($groups === []) {
        echo "No SHA256 duplicates found.\n";
        echo "groups=0 rows=0\n";
        return $exitCode;
    }

    $groupCount = 0;
    $rowCount = 0;
    foreach ($groups as $group) {
        $groupCount++;
        $sha256 = (string) ($group['sha256'] ?? '');
        $count = (int) ($group['count'] ?? 0);
        echo 'sha256: ' . $sha256 . "\n";
        echo 'count: ' . $count . "\n";

        foreach ($group['items'] as $item) {
            $rowCount++;
            $disk = (string) ($item['disk'] ?? '');
            if ($disk === '') {
                $disk = $defaultDisk !== '' ? $defaultDisk : '-';
            }
            $path = $withPaths ? (' ' . (string) ($item['disk_path'] ?? '')) : '';
            $size = (int) ($item['size_bytes'] ?? 0);
            $created = (string) ($item['created_at'] ?? '');
            $status = (string) ($item['status'] ?? '');
            $id = (int) ($item['id'] ?? 0);
            echo '  [' . $id . '] ' . $disk . $path . ' ' . $size . ' ' . $created . ' ' . $status . "\n";
        }
    }

    echo 'groups=' . $groupCount . ' rows=' . $rowCount . "\n";
    return $exitCode;
};

$commands['media:verify'] = function () use ($dbManager, $rootPath, $storageConfig, $args): int {
    if (!$dbManager->healthCheck()) {
        echo "DB not available.\n";
        return 1;
    }

    $disk = (string) (getOption($args, 'disk') ?? ($storageConfig['default'] ?? 'local'));
    $error = '';
    $driver = buildStorageDriver($rootPath, $storageConfig, $disk, $error);
    if ($driver === null) {
        echo "Storage not available.\n";
        return 1;
    }

    $limit = 0;
    $limitRaw = getOption($args, 'limit');
    if ($limitRaw !== null && is_numeric($limitRaw)) {
        $limit = max(0, (int) $limitRaw);
    }

    $repo = new MediaRepository($dbManager);
    $service = new MediaVerifyService($rootPath, $repo, $driver);
    $result = $service->verify($limit);

    echo 'ok_count: ' . (int) ($result['ok_count'] ?? 0) . "\n";
    echo 'missing_count: ' . (int) ($result['missing_count'] ?? 0) . "\n";
    echo 'mismatch_count: ' . (int) ($result['mismatch_count'] ?? 0) . "\n";
    echo 'scanned: ' . (int) ($result['scanned'] ?? 0) . "\n";

    $issues = (int) ($result['missing_count'] ?? 0) + (int) ($result['mismatch_count'] ?? 0);
    return $issues > 0 ? 2 : 0;
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
        $includeDb = parseBoolOption($args, 'include-db', true);
        $includeMedia = parseBoolOption($args, 'include-media', true);
        $result = $manager->create([
            'db_driver' => $driver,
            'include_db' => $includeDb,
            'include_media' => $includeMedia,
        ]);
        if (!$result['ok']) {
            echo "Backup failed.\n";
            return 1;
        }

        echo 'Backup created: ' . ($result['file'] ?? '') . "\n";
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

    $dryRun = parseBoolOption($args, 'dry-run', false);
    $force = parseBoolOption($args, 'force', false);
    $input1 = '';
    $input2 = '';
    if (!$dryRun) {
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
    }

    $storage = new StorageService($rootPath);
    $manager = new BackupManager($rootPath, $dbManager, $storage, $appConfig, $storageConfig);
    $result = $manager->restore($file, [
        'force' => $force,
        'dry_run' => $dryRun,
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

    if ($dryRun) {
        echo "Plan:\n";
        $plan = $result['plan'] ?? [];
        echo '- DB import: ' . (!empty($plan['db']) ? 'yes' : 'no') . "\n";
        echo '- Media restore: ' . (!empty($plan['media']) ? 'yes' : 'no') . "\n";
        $targets = is_array($plan['targets'] ?? null) ? $plan['targets'] : [];
        echo "- Target paths:\n";
        echo '  - db: ' . (string) ($targets['db'] ?? '-') . "\n";
        echo '  - media: ' . (string) ($targets['media'] ?? '-') . "\n";
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
    $manifest = $result['manifest'] ?? [];

    echo $translator->trans('backup.inspect.ok') . "\n";
    foreach ($meta as $key => $value) {
        echo $key . ': ' . (is_scalar($value) ? (string) $value : json_encode($value)) . "\n";
    }
    foreach ($checks as $key => $value) {
        echo 'check.' . $key . ': ' . ($value ? 'ok' : 'fail') . "\n";
    }
    $files = $manifest['files'] ?? null;
    if (is_array($files)) {
        echo 'files.count: ' . count($files) . "\n";
    }
    return 0;
};

$commands['backup:verify'] = function () use ($rootPath, $dbManager, $storageConfig, $appConfig, $args): int {
    $file = $args[0] ?? '';
    if ($file === '') {
        echo "Usage: backup:verify <file>\n";
        return 1;
    }

    $storage = new StorageService($rootPath);
    $manager = new BackupManager($rootPath, $dbManager, $storage, $appConfig, $storageConfig);
    $result = $manager->verify($file);
    if (!$result['ok']) {
        echo "Verify failed.\n";
        $errors = $result['errors'] ?? [];
        if (is_array($errors) && $errors !== []) {
            foreach ($errors as $error) {
                echo '- ' . (string) $error . "\n";
            }
        }
        return 2;
    }

    echo "OK\n";
    return 0;
};

$commands['backup:prune'] = function () use ($rootPath, $dbManager, $storageConfig, $appConfig, $args): int {
    $keep = (int) (getOption($args, 'keep') ?? 10);
    if ($keep < 0) {
        $keep = 10;
    }

    $storage = new StorageService($rootPath);
    $manager = new BackupManager($rootPath, $dbManager, $storage, $appConfig, $storageConfig);
    $result = $manager->prune($keep);
    echo "Pruned: " . (int) ($result['deleted'] ?? 0) . "\n";
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

$commands['release:check'] = function () use ($rootPath, $dbManager, $appConfig, $storageConfig, $getMigrator): int {
    $mediaConfig = is_file($rootPath . '/config/media.php') ? require $rootPath . '/config/media.php' : [];
    $dbConfig = is_file($rootPath . '/config/database.php') ? require $rootPath . '/config/database.php' : [];
    $storage = new StorageService($rootPath);
    $backup = new BackupManager($rootPath, $dbManager, $storage, $appConfig, $storageConfig);
    $migrator = $getMigrator();

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

$commands['contracts:dump'] = function () use ($appConfig): int {
    $appVersion = is_string($appConfig['version'] ?? null) ? (string) $appConfig['version'] : '';
    $payload = ContractDump::build($appVersion);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    return 0;
};

$commands['contracts:snapshot:update'] = function () use ($rootPath, $appConfig): int {
    $dir = $rootPath . '/tests/fixtures/contracts';
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }

    $appVersion = is_string($appConfig['version'] ?? null) ? (string) $appConfig['version'] : '';
    $dump = ContractDump::build($appVersion);
    $normalized = ContractFixtureNormalizer::normalize($dump);
    $json = json_encode($normalized, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        echo "Failed to encode snapshot.\n";
        return 1;
    }

    $file = $dir . '/_snapshot.json';
    file_put_contents($file, $json . "\n");
    echo "Wrote: {$file}\n";
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
    $files = array_values(array_filter($files, static function (string $file): bool {
        return basename($file) !== '_snapshot.json';
    }));
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

$commands['contracts:check'] = function () use ($rootPath, $appConfig): int {
    $errors = 0;
    $warnings = 0;

    $appVersion = is_string($appConfig['version'] ?? null) ? (string) $appConfig['version'] : '';
    $dump = ContractDump::build($appVersion);
    if (($dump['contracts_version'] ?? '') === '') {
        echo "Missing contracts_version.\n";
        $errors++;
    }

    $dir = $rootPath . '/tests/fixtures/contracts';
    if (!is_dir($dir)) {
        echo "Fixtures directory not found.\n";
        return 1;
    }

    $files = glob($dir . '/*.json') ?: [];
    $files = array_values(array_filter($files, static function (string $file): bool {
        return basename($file) !== '_snapshot.json';
    }));
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

    $fixtures = collectContractFixtures($contracts);
    $expectedFixtures = [];
    foreach ($fixtures as $fixture) {
        $expectedFixtures[$fixture['fixture']] = $fixture;
    }

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

        if (isset($expectedFixtures[$fixtureName])) {
            $expected = ContractFixtureNormalizer::normalize($expectedFixtures[$fixtureName]['payload']);
            if ($payload !== $expected) {
                echo "Fixture mismatch: {$fixtureName}\n";
                $errors++;
            }
        }
    }

    foreach ($expectedFixtures as $fixtureName => $fixture) {
        $spec = $specByName[$fixture['contract']] ?? [];
        $noFixture = (bool) ($spec['no_fixture'] ?? false);
        $path = $dir . '/' . $fixtureName . '.json';
        if (!is_file($path)) {
            if ($noFixture) {
                echo "WARN: Missing fixture (no_fixture): {$fixtureName}\n";
                $warnings++;
            } else {
                echo "Missing fixture: {$fixtureName}\n";
                $errors++;
            }
        }
    }

    if ($errors > 0) {
        return 1;
    }

    if ($warnings > 0) {
        echo "OK (warnings={$warnings})\n";
        return 0;
    }

    echo "OK\n";
    return 0;
};

$commands['session:smoke'] = function () use ($rootPath, $securityConfig, $logger): int {
    $sessionCheck = new SessionCheck($securityConfig['session'] ?? [], $rootPath);
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

$commands['session:doctor'] = function () use ($rootPath, $securityConfig, $logger): int {
    $sessionConfig = $securityConfig['session'] ?? [];
    $factory = new SessionFactory($sessionConfig, $logger, $rootPath);
    $sessionCheck = new SessionCheck($sessionConfig, $rootPath);
    $result = $sessionCheck->run();
    echo $result['message'] . "\n";

    $driver = strtolower(trim((string) ($sessionConfig['driver'] ?? 'native')));
    $handler = $driver === 'redis' ? 'redis' : 'files';
    echo 'handler: ' . $handler . "\n";

    if ($driver === 'redis') {
        $redis = $sessionConfig['redis'] ?? [];
        $url = (string) ($redis['url'] ?? '');
        $timeout = (float) ($redis['timeout'] ?? 1.5);
        $target = SessionCheck::formatTarget($url);
        $suffix = $target !== '' ? (' (' . $target . ')') : '';
        try {
            $client = RedisClient::fromUrl($url, $timeout);
            $client->connect();
            $client->ping();
            echo 'redis reachable: yes' . $suffix . "\n";
        } catch (Throwable) {
            echo 'redis reachable: no' . $suffix . "\n";
        }
    }

    $idle = (int) ($sessionConfig['idle_ttl'] ?? 0);
    $absolute = (int) ($sessionConfig['absolute_ttl'] ?? 0);
    $legacyTimeout = (int) ($sessionConfig['timeout'] ?? 0);

    $idleLabel = $idle > 0
        ? ($idle . ' min')
        : ($legacyTimeout > 0 ? ((int) ceil($legacyTimeout / 60)) . ' min (legacy)' : 'disabled');
    $absoluteLabel = $absolute > 0 ? ($absolute . ' min') : 'disabled';

    $gcMax = (int) ini_get('session.gc_maxlifetime');
    $gcProb = (int) ini_get('session.gc_probability');
    $gcDiv = (int) ini_get('session.gc_divisor');

    $cookie = $factory->cookiePolicy(true);
    echo 'idle ttl: ' . $idleLabel . "\n";
    echo 'absolute ttl: ' . $absoluteLabel . "\n";
    echo 'gc_maxlifetime: ' . $gcMax . "s\n";
    echo 'gc_probability: ' . $gcProb . ' gc_divisor: ' . $gcDiv . "\n";
    echo 'cookie: name=' . $cookie['name']
        . ' samesite=' . $cookie['samesite']
        . ' secure=' . ($cookie['secure'] ? 'true' : 'false')
        . ' httponly=' . ($cookie['httponly'] ? 'true' : 'false')
        . ' domain=' . ($cookie['domain'] !== '' ? $cookie['domain'] : '-')
        . ' path=' . $cookie['path'] . "\n";

    if ($result['code'] === 1) {
        return 1;
    }
    if ($result['code'] === 2) {
        return 2;
    }

    return 0;
};

$commands['doctor'] = function () use (&$commands, $rootPath, $appConfig, $storageConfig, $securityConfig): int {
    $runner = new PreflightRunner();
    $dbConfigured = dbEnvConfigured();
    $sessionCheck = new SessionCheck($securityConfig['session'] ?? [], $rootPath);
    $backupCheck = new BackupWritableCheck($rootPath);
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
            'label' => 'security_headers',
            'enabled' => true,
            'run' => static function () use ($securityConfig): int {
                $check = new SecurityHeadersCheck($securityConfig, null);
                $result = $check->run();
                echo $result['message'] . "\n";
                return $result['code'];
            },
        ],
        [
            'label' => 'backup_writable',
            'enabled' => true,
            'run' => static function () use ($backupCheck): int {
                $result = $backupCheck->run();
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

$commands['preflight'] = function () use (&$commands, $args, $rootPath, $securityConfig, $dbManager, $appConfig): int {
    $noTests = hasFlag($args, 'no-tests');
    $noDb = hasFlag($args, 'no-db');
    $strict = hasFlag($args, 'strict');

    $runner = new PreflightRunner();
    $sessionCheck = new SessionCheck($securityConfig['session'] ?? [], $rootPath);
    $backupCheck = new BackupWritableCheck($rootPath);
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
            'label' => 'security_headers',
            'enabled' => true,
            'run' => static function () use ($securityConfig): int {
                $check = new SecurityHeadersCheck($securityConfig, null);
                $result = $check->run();
                echo $result['message'] . "\n";
                return $result['code'];
            },
        ],
        [
            'label' => 'backup_writable',
            'enabled' => true,
            'run' => static function () use ($backupCheck): int {
                $result = $backupCheck->run();
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
        [
            'label' => 'db:indexes',
            'enabled' => !$noDb,
            'run' => static function () use ($dbManager, $appConfig): int {
                if (!$dbManager->healthCheck()) {
                    echo "DB not available.\n";
                    return 1;
                }
                $inspector = new DbIndexInspector($dbManager->pdo());
                $result = $inspector->auditRequired();
                if (!empty($result['ok'])) {
                    echo "db.indexes.ok\n";
                    return 0;
                }
                $prefix = isProdEnv($appConfig) ? '' : 'WARN: ';
                echo $prefix . "db.indexes.missing\n";
                return isProdEnv($appConfig) ? 2 : 0;
            },
        ],
    ];

    $result = $runner->run($steps);
    $runner->printReport($result['results']);
    return $result['code'];
};

$commands['assets:verify'] = function () use ($rootPath, $args): int {
    require_once $rootPath . '/tools/assets-verify.php';
    $override = (string) (getOption($args, 'root') ?? '');
    $root = $override !== '' ? $override : $rootPath;
    return assets_verify_run($root);
};

$commands['policy:check'] = function () use ($rootPath): int {
    require_once $rootPath . '/tools/policy-check.php';
    require_once $rootPath . '/tools/assets-verify.php';
    $assetsCode = assets_verify_run($rootPath);
    $policyCode = policy_run([
        $rootPath . '/themes',
        $rootPath . '/src',
        $rootPath . '/modules',
    ]);
    return max($assetsCode, $policyCode);
};

$commands['theme:validate'] = function () use ($rootPath, $appConfig, $dbManager, $args): int {
    $themesRoot = (string) (getOption($args, 'themes-root') ?? ($rootPath . '/themes'));
    $schemaPath = (string) (getOption($args, 'schema') ?? '');
    $snapshotPath = (string) (getOption($args, 'snapshot') ?? ($rootPath . '/config/theme_snapshot.php'));
    $acceptSnapshot = hasFlag($args, 'accept-snapshot') || hasFlag($args, 'update-snapshot');

    $validator = new ThemeValidator(
        $themesRoot,
        $schemaPath !== '' ? $schemaPath : null,
        $snapshotPath !== '' ? $snapshotPath : null
    );
    $themes = [];

    $themeArgs = array_filter($args, static fn($arg) => !str_starts_with($arg, '--'));
    if ($themeArgs !== []) {
        $themes = $themeArgs;
    } else {
        $settings = new SettingsProvider(
            $dbManager,
            [
                'site_name' => $appConfig['name'] ?? 'LAAS',
                'default_locale' => $appConfig['default_locale'] ?? 'en',
                'theme' => $appConfig['theme'] ?? 'default',
            ],
            ['site_name', 'default_locale', 'theme']
        );
        $active = (string) $settings->get('theme', $appConfig['theme'] ?? 'default');
        $themes = array_values(array_unique([$active, 'default', 'admin']));
    }

    $exit = 0;
    foreach ($themes as $theme) {
        $theme = (string) $theme;
        if ($theme === '') {
            continue;
        }
            $result = $validator->validateTheme($theme, $acceptSnapshot);
            if (!$result->hasViolations()) {
                echo 'Theme ' . $theme . ": OK\n";
                continue;
            }
        $exit = 2;
        echo 'Theme ' . $theme . ": VIOLATIONS\n";
        foreach ($result->getViolations() as $violation) {
            $code = (string) ($violation['code'] ?? '');
            $file = (string) ($violation['file'] ?? '');
            $message = (string) ($violation['message'] ?? '');
            echo '- [' . $code . '] ' . $file . ' ' . $message . "\n";
        }
    }

    return $exit;
};

$commands['themes:validate'] = static function () use (&$commands): int {
    return $commands['theme:validate']();
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

function normalizePath(string $value): string
{
    return str_replace('\\', '/', $value);
}

function isAbsolutePath(string $value): bool
{
    if ($value === '') {
        return false;
    }
    if (str_starts_with($value, '/') || str_starts_with($value, '\\')) {
        return true;
    }
    return preg_match('/^[A-Za-z]:[\\\\\\/]/', $value) === 1;
}

/**
 * @param array<int, string> $extensions
 * @return array{files_scanned: int, items: array<int, array{file: string, line: int, kind: string, excerpt: string}>}|null
 */
function scanTemplateRaw(string $rootPath, string $pathArg, array $extensions): ?array
{
    $basePath = isAbsolutePath($pathArg) ? $pathArg : $rootPath . '/' . ltrim($pathArg, '/\\');
    if (!is_dir($basePath) && !is_file($basePath)) {
        return null;
    }

    $files = [];
    if (is_file($basePath)) {
        $files[] = $basePath;
    } else {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($basePath, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $fileInfo) {
            if (!$fileInfo->isFile()) {
                continue;
            }
            $ext = strtolower($fileInfo->getExtension());
            if (!in_array($ext, $extensions, true)) {
                continue;
            }
            $files[] = $fileInfo->getPathname();
        }
    }

    $items = [];
    $filesScanned = 0;
    $rootNorm = rtrim(normalizePath($rootPath), '/');
    $pattern = '/\\{%\\s*(raw|endraw)\\b([^%}]*)%\\}/';
    $altPattern = '/\\{\\{\\s*raw\\b/';

    foreach ($files as $filePath) {
        $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        if (!in_array($ext, $extensions, true)) {
            continue;
        }
        $filesScanned++;
        $lines = @file($filePath, FILE_IGNORE_NEW_LINES);
        if (!is_array($lines)) {
            continue;
        }

        $reportPath = normalizePath($filePath);
        if (str_starts_with(strtolower($reportPath), strtolower($rootNorm . '/'))) {
            $reportPath = substr($reportPath, strlen($rootNorm) + 1);
        }

        foreach ($lines as $index => $line) {
            $lineNumber = $index + 1;
            $excerpt = str_replace("\t", ' ', $line);
            $excerpt = trim($excerpt);
            if (strlen($excerpt) > 120) {
                $excerpt = substr($excerpt, 0, 120);
            }

            if (preg_match_all($pattern, $line, $matches, PREG_SET_ORDER)) {
                foreach ($matches as $match) {
                    $tag = strtolower((string) ($match[1] ?? ''));
                    $rest = trim((string) ($match[2] ?? ''));
                    if ($tag === 'endraw') {
                        continue;
                    }
                    $kind = $rest === '' ? 'raw_block' : 'raw_output';
                    $items[] = [
                        'file' => $reportPath,
                        'line' => $lineNumber,
                        'kind' => $kind,
                        'excerpt' => $excerpt,
                    ];
                }
            }

            if (preg_match_all($altPattern, $line, $altMatches, PREG_SET_ORDER)) {
                foreach ($altMatches as $match) {
                    $items[] = [
                        'file' => $reportPath,
                        'line' => $lineNumber,
                        'kind' => 'raw_output',
                        'excerpt' => $excerpt,
                    ];
                }
            }
        }
    }

    return [
        'files_scanned' => $filesScanned,
        'items' => $items,
    ];
}

function parseBoolOption(array $args, string $name, bool $default): bool
{
    $value = getOption($args, $name);
    if ($value === null) {
        return hasFlag($args, $name) ? true : $default;
    }

    $parsed = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    return $parsed ?? $default;
}

function parseDurationSeconds(string $value): ?int
{
    $value = trim($value);
    if ($value === '') {
        return null;
    }

    if (ctype_digit($value)) {
        return (int) $value;
    }

    if (!preg_match('/^(\d+)\s*([smhd])$/i', $value, $matches)) {
        return null;
    }

    $amount = (int) $matches[1];
    $unit = strtolower($matches[2]);
    return match ($unit) {
        's' => $amount,
        'm' => $amount * 60,
        'h' => $amount * 3600,
        'd' => $amount * 86400,
        default => null,
    };
}

function buildStorageDriver(string $rootPath, array $storageConfig, string $disk, ?string &$error = null): ?object
{
    $disk = strtolower(trim($disk));
    if ($disk === '') {
        $disk = 'local';
    }

    if (!in_array($disk, ['local', 's3'], true)) {
        $error = 'invalid_disk';
        return null;
    }

    if ($disk === 'local') {
        return new LocalStorageDriver($rootPath);
    }

    $s3 = $storageConfig['disks']['s3'] ?? null;
    if (!is_array($s3)) {
        $error = 's3_config_missing';
        return null;
    }
    if (($s3['region'] ?? '') === '' || ($s3['bucket'] ?? '') === '' || ($s3['access_key'] ?? '') === '' || ($s3['secret_key'] ?? '') === '') {
        $error = 's3_config_missing';
        return null;
    }

    try {
        return new S3Storage($s3);
    } catch (Throwable) {
        $error = 's3_init_failed';
        return null;
    }
}

/**
 * @param array<int, array{path: string, message: string}> $errors
 */
function outputProposalValidation(array $errors, bool $json): int
{
    $valid = $errors === [];
    if ($json) {
        echo json_encode([
            'valid' => $valid,
            'errors' => $errors,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
    } else {
        if ($valid) {
            echo "valid=1 errors=0\n";
        } else {
            echo 'valid=0 errors=' . count($errors) . "\n";
            foreach ($errors as $error) {
                $path = (string) ($error['path'] ?? '');
                $message = (string) ($error['message'] ?? '');
                echo '- ' . $path . ': ' . $message . "\n";
            }
        }
    }

    return $valid ? 0 : 3;
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

function resolveMigrationSafeMode(array $dbConfig, array $appConfig): string
{
    $mode = strtolower((string) ($dbConfig['migrations']['safe_mode'] ?? ''));
    if (!in_array($mode, ['off', 'warn', 'block'], true)) {
        $mode = 'off';
    }

    return $mode;
}

function isProdEnv(array $appConfig): bool
{
    return strtolower((string) ($appConfig['env'] ?? '')) === 'prod';
}

/** @return array<string, string> */
function resolvePendingMigrations(Migrator $migrator, int $steps): array
{
    $discovered = $migrator->discoverMigrations();
    $applied = $migrator->appliedMigrations();

    $pending = [];
    foreach ($discovered as $name => $path) {
        if (!array_key_exists($name, $applied)) {
            $pending[$name] = $path;
        }
    }

    if ($steps > 0) {
        $pending = array_slice($pending, 0, $steps, true);
    }

    return $pending;
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
