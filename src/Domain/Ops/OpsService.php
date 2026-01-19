<?php
declare(strict_types=1);

namespace Laas\Domain\Ops;

use DateTimeImmutable;
use Laas\Database\DatabaseManager;
use Laas\Domain\Security\SecurityReportsService;
use Laas\Modules\Media\Service\StorageService;
use Laas\Ops\Checks\BackupWritableCheck;
use Laas\Ops\Checks\SecurityHeadersCheck;
use Laas\Ops\Checks\SessionCheck;
use Laas\Session\Redis\RedisSessionFailover;
use Laas\Support\Cache\CacheFactory;
use Laas\Support\ConfigSanityChecker;
use Laas\Support\HealthService;
use Laas\Support\SessionConfigValidator;
use RuntimeException;
use Throwable;

class OpsService
{
    public function __construct(
        private DatabaseManager $db,
        private array $config,
        private string $rootPath,
        private SecurityReportsService $securityReports
    ) {
    }

    /** @return array<string, mixed> */
    public function overview(bool $isHttps): array
    {
        $appConfig = $this->config['app'] ?? [];
        $securityConfig = $this->config['security'] ?? [];
        $storageConfig = $this->config['storage'] ?? [];
        $mediaConfig = $this->config['media'] ?? [];
        $perfConfig = $this->config['perf'] ?? [];

        $health = $this->buildHealth($isHttps, $appConfig, $securityConfig, $storageConfig, $mediaConfig);
        $sessions = $this->buildSessions($securityConfig);
        $backups = $this->buildBackups();
        $health['checks']['session'] = $sessions['status'] ?? 'warn';
        $health['checks']['backup'] = $backups['writable'] ?? 'warn';
        $performance = $this->buildPerformance($perfConfig);
        $cache = $this->buildCache();
        $security = $this->buildSecurity($isHttps, $securityConfig);
        $preflight = $this->buildPreflight($appConfig, $storageConfig);

        return [
            'health' => $health,
            'sessions' => $sessions,
            'backups' => $backups,
            'performance' => $performance,
            'cache' => $cache,
            'security' => $security,
            'preflight' => $preflight,
        ];
    }

    /** @return array<string, mixed> */
    public function viewData(array $snapshot, callable $translate): array
    {
        $health = $snapshot['health'] ?? [];
        $sessions = $snapshot['sessions'] ?? [];
        $backups = $snapshot['backups'] ?? [];
        $performance = $snapshot['performance'] ?? [];
        $cache = $snapshot['cache'] ?? [];
        $security = $snapshot['security'] ?? [];
        $preflight = $snapshot['preflight'] ?? [];

        return [
            'health' => $this->decorateHealth($health, $translate),
            'sessions' => $this->decorateSessions($sessions),
            'backups' => $this->decorateBackups($backups),
            'performance' => $this->decoratePerformance($performance),
            'cache' => $this->decorateCache($cache),
            'security' => $this->decorateSecurity($security),
            'preflight' => $this->decoratePreflight($preflight),
        ];
    }

    private function buildHealth(
        bool $isHttps,
        array $appConfig,
        array $securityConfig,
        array $storageConfig,
        array $mediaConfig
    ): array {
        $storage = new StorageService($this->rootPath);
        $checker = new ConfigSanityChecker();
        $writeCheck = (bool) ($appConfig['health_write_check'] ?? false);
        $healthService = new HealthService(
            $this->rootPath,
            fn (): bool => $this->db->healthCheck(),
            $storage,
            $checker,
            [
                'media' => is_array($mediaConfig) ? $mediaConfig : [],
                'storage' => is_array($storageConfig) ? $storageConfig : [],
                'session' => is_array($securityConfig['session'] ?? null) ? $securityConfig['session'] : [],
            ],
            $writeCheck,
            new SessionConfigValidator()
        );

        $result = $healthService->check();
        $checks = is_array($result['checks'] ?? null) ? $result['checks'] : [];
        $warnings = is_array($result['warnings'] ?? null) ? $result['warnings'] : [];

        $securityCheck = new SecurityHeadersCheck($securityConfig, $isHttps);
        $securityResult = $securityCheck->run();
        $securityStatus = $this->statusFromCode($securityResult['code'] ?? 0);

        $ok = (bool) ($result['ok'] ?? false);
        if ($securityStatus === 'fail') {
            $ok = false;
            $warnings[] = (string) ($securityResult['message'] ?? 'security headers: FAIL');
        } elseif ($securityStatus === 'warn') {
            $warnings[] = (string) ($securityResult['message'] ?? 'security headers: WARN');
        }

        return [
            'status' => $ok ? 'ok' : 'degraded',
            'checks' => [
                'db' => $this->statusFromBool((bool) ($checks['db'] ?? false)),
                'storage' => $this->statusFromBool((bool) ($checks['storage'] ?? false)),
                'fs' => $this->statusFromBool((bool) ($checks['fs'] ?? false)),
                'security_headers' => $securityStatus,
            ],
            'warnings' => array_values(array_map('strval', $warnings)),
            'updated_at' => gmdate('c'),
        ];
    }

    private function buildSessions(array $securityConfig): array
    {
        $sessionConfig = is_array($securityConfig['session'] ?? null) ? $securityConfig['session'] : [];
        $check = new SessionCheck($sessionConfig, $this->rootPath);
        $result = $check->run();
        $status = $this->statusFromCode($result['code'] ?? 0);
        $driverRaw = strtolower(trim((string) ($sessionConfig['driver'] ?? 'native')));

        $failover = null;
        if ($driverRaw === 'redis') {
            $failover = (new RedisSessionFailover($this->rootPath))->hasRecentFailure();
        }

        return [
            'driver' => $driverRaw === 'redis' ? 'redis' : 'php',
            'status' => $status,
            'failover_active' => $failover ?? false,
            'details' => $this->splitLines((string) ($result['message'] ?? '')),
        ];
    }

    private function buildBackups(): array
    {
        $check = new BackupWritableCheck($this->rootPath);
        $result = $check->run();
        $last = $this->latestBackup($this->rootPath . '/storage/backups');

        return [
            'writable' => $this->statusFromCode($result['code'] ?? 0),
            'writable_details' => $this->splitLines((string) ($result['message'] ?? '')),
            'last_backup' => $last,
            'retention' => [
                'keep' => $this->envInt('BACKUP_KEEP', 10),
                'policy' => 'manual',
            ],
            'verify_supported' => true,
        ];
    }

    private function buildPerformance(array $perfConfig): array
    {
        $guardEnabled = (bool) ($perfConfig['guard_enabled'] ?? false);
        $guardMode = strtolower((string) ($perfConfig['guard_mode'] ?? 'warn'));
        if (!in_array($guardMode, ['warn', 'block'], true)) {
            $guardMode = 'warn';
        }

        return [
            'guard_mode' => $guardEnabled ? $guardMode : 'off',
            'budgets' => [
                'total_ms_warn' => (int) ($perfConfig['total_ms_warn'] ?? 0),
                'total_ms_hard' => (int) ($perfConfig['total_ms_hard'] ?? 0),
                'sql_count_warn' => (int) ($perfConfig['sql_count_warn'] ?? 0),
                'sql_count_hard' => (int) ($perfConfig['sql_count_hard'] ?? 0),
                'sql_ms_warn' => (int) ($perfConfig['sql_ms_warn'] ?? 0),
                'sql_ms_hard' => (int) ($perfConfig['sql_ms_hard'] ?? 0),
            ],
            'guard_limits' => [
                'db_max_queries' => (int) ($perfConfig['db_max_queries'] ?? 0),
                'db_max_unique' => (int) ($perfConfig['db_max_unique'] ?? 0),
                'db_max_total_ms' => (int) ($perfConfig['db_max_total_ms'] ?? 0),
                'http_max_calls' => (int) ($perfConfig['http_max_calls'] ?? 0),
                'http_max_total_ms' => (int) ($perfConfig['http_max_total_ms'] ?? 0),
                'total_max_ms' => (int) ($perfConfig['total_max_ms'] ?? 0),
            ],
            'admin_override' => [
                'enabled' => (int) ($perfConfig['db_max_queries_admin'] ?? 0) > 0
                    || (int) ($perfConfig['total_max_ms_admin'] ?? 0) > 0,
            ],
        ];
    }

    private function buildCache(): array
    {
        $config = CacheFactory::config($this->rootPath);
        $enabled = (bool) ($config['enabled'] ?? true);
        $defaultTtl = (int) ($config['ttl_default'] ?? $config['default_ttl'] ?? 300);
        $tagTtl = (int) ($config['tag_ttl'] ?? $defaultTtl);
        $ttlDays = (int) ($config['ttl_days'] ?? 7);

        $lastPrune = null;
        $pruneFile = $this->rootPath . '/storage/cache/.prune.json';
        if (is_file($pruneFile)) {
            $raw = json_decode((string) file_get_contents($pruneFile), true);
            if (is_array($raw) && isset($raw['at'])) {
                $lastPrune = gmdate('Y-m-d\\TH:i:s\\Z', (int) $raw['at']);
            }
        }

        return [
            'enabled' => $enabled,
            'driver' => $enabled ? 'file' : 'null',
            'default_ttl' => $defaultTtl,
            'tag_ttl' => $tagTtl,
            'ttl_days' => $ttlDays,
            'last_prune' => $lastPrune,
        ];
    }

    private function buildSecurity(bool $isHttps, array $securityConfig): array
    {
        $headers = new SecurityHeadersCheck($securityConfig, $isHttps);
        $result = $headers->run();
        $reports = $this->countSecurityReports();

        return [
            'headers_status' => $this->statusFromCode($result['code'] ?? 0),
            'headers_details' => $this->splitLines((string) ($result['message'] ?? '')),
            'reports' => $reports,
        ];
    }

    private function buildPreflight(array $appConfig, array $storageConfig): array
    {
        $storageDisk = (string) ($storageConfig['default'] ?? $storageConfig['default_raw'] ?? '');
        if ($storageDisk === '') {
            $storageDisk = (string) (getenv('STORAGE_DISK') ?: '');
        }
        if ($storageDisk === '') {
            $storageDisk = 'local';
        }

        return [
            'commands' => [
                'php tools/cli.php preflight',
                'php tools/cli.php doctor',
                'php tools/cli.php ops:check',
            ],
            'env' => [
                'app_env' => (string) ($appConfig['env'] ?? 'dev'),
                'app_debug' => (bool) ($appConfig['debug'] ?? false),
                'read_only' => (bool) ($appConfig['read_only'] ?? false),
                'headless' => (bool) ($appConfig['headless_mode'] ?? false),
                'storage_disk' => $storageDisk,
            ],
        ];
    }

    private function decorateHealth(array $health, callable $translate): array
    {
        $status = (string) ($health['status'] ?? 'degraded');
        $statusLabel = $status === 'ok'
            ? $translate('system.health.ok')
            : $translate('system.health.degraded');
        $statusBadge = $status === 'ok' ? 'success' : 'warning';

        $checks = [];
        $rawChecks = is_array($health['checks'] ?? null) ? $health['checks'] : [];
        $checksMap = [
            'DB' => (string) ($rawChecks['db'] ?? 'fail'),
            'Storage' => (string) ($rawChecks['storage'] ?? 'fail'),
            'Filesystem' => (string) ($rawChecks['fs'] ?? 'fail'),
            'Session' => (string) ($rawChecks['session'] ?? 'warn'),
            'Backup' => (string) ($rawChecks['backup'] ?? 'warn'),
            'Security headers' => (string) ($rawChecks['security_headers'] ?? 'warn'),
        ];

        foreach ($checksMap as $label => $value) {
            $token = $this->statusToken($value);
            $checks[] = [
                'label' => $label,
                'status_label' => $token['label'],
                'badge' => $token['badge'],
            ];
        }

        $warnings = is_array($health['warnings'] ?? null) ? $health['warnings'] : [];

        return [
            'status_label' => $statusLabel,
            'status_badge' => $statusBadge,
            'checks' => $checks,
            'warnings' => array_values(array_map('strval', $warnings)),
        ];
    }

    private function decorateSessions(array $sessions): array
    {
        $status = (string) ($sessions['status'] ?? 'warn');
        $token = $this->statusToken($status);

        return [
            'status_label' => $token['label'],
            'status_badge' => $token['badge'],
            'driver' => (string) ($sessions['driver'] ?? 'php'),
            'failover_active' => (bool) ($sessions['failover_active'] ?? false),
            'details' => is_array($sessions['details'] ?? null) ? $sessions['details'] : [],
        ];
    }

    private function decorateBackups(array $backups): array
    {
        $status = (string) ($backups['writable'] ?? 'warn');
        $token = $this->statusToken($status);
        $last = is_array($backups['last_backup'] ?? null) ? $backups['last_backup'] : [];
        $retention = is_array($backups['retention'] ?? null) ? $backups['retention'] : [];

        return [
            'status_label' => $token['label'],
            'status_badge' => $token['badge'],
            'details' => is_array($backups['writable_details'] ?? null) ? $backups['writable_details'] : [],
            'last_backup' => [
                'name' => (string) ($last['name'] ?? ''),
                'created_at' => (string) ($last['created_at'] ?? ''),
            ],
            'retention' => [
                'keep' => (int) ($retention['keep'] ?? 0),
                'policy' => (string) ($retention['policy'] ?? ''),
            ],
            'verify_supported' => (bool) ($backups['verify_supported'] ?? false),
        ];
    }

    private function decoratePerformance(array $performance): array
    {
        $guardMode = (string) ($performance['guard_mode'] ?? 'off');
        $budgets = is_array($performance['budgets'] ?? null) ? $performance['budgets'] : [];
        $guards = is_array($performance['guard_limits'] ?? null) ? $performance['guard_limits'] : [];
        $override = is_array($performance['admin_override'] ?? null) ? $performance['admin_override'] : [];

        return [
            'guard_mode' => $guardMode,
            'budgets' => [
                'total_ms_warn' => (int) ($budgets['total_ms_warn'] ?? 0),
                'total_ms_hard' => (int) ($budgets['total_ms_hard'] ?? 0),
                'sql_count_warn' => (int) ($budgets['sql_count_warn'] ?? 0),
                'sql_count_hard' => (int) ($budgets['sql_count_hard'] ?? 0),
                'sql_ms_warn' => (int) ($budgets['sql_ms_warn'] ?? 0),
                'sql_ms_hard' => (int) ($budgets['sql_ms_hard'] ?? 0),
            ],
            'guard_limits' => [
                'db_max_queries' => (int) ($guards['db_max_queries'] ?? 0),
                'db_max_unique' => (int) ($guards['db_max_unique'] ?? 0),
                'db_max_total_ms' => (int) ($guards['db_max_total_ms'] ?? 0),
                'http_max_calls' => (int) ($guards['http_max_calls'] ?? 0),
                'http_max_total_ms' => (int) ($guards['http_max_total_ms'] ?? 0),
                'total_max_ms' => (int) ($guards['total_max_ms'] ?? 0),
            ],
            'admin_override' => [
                'enabled' => (bool) ($override['enabled'] ?? false),
            ],
        ];
    }

    private function decorateCache(array $cache): array
    {
        return [
            'enabled' => (bool) ($cache['enabled'] ?? false),
            'driver' => (string) ($cache['driver'] ?? 'file'),
            'default_ttl' => (int) ($cache['default_ttl'] ?? 0),
            'tag_ttl' => (int) ($cache['tag_ttl'] ?? 0),
            'ttl_days' => (int) ($cache['ttl_days'] ?? 0),
            'last_prune' => (string) ($cache['last_prune'] ?? ''),
        ];
    }

    private function decorateSecurity(array $security): array
    {
        $status = (string) ($security['headers_status'] ?? 'warn');
        $token = $this->statusToken($status);
        $reports = is_array($security['reports'] ?? null) ? $security['reports'] : [];
        $last = $reports['last_24h'] ?? null;
        $total = $reports['total'] ?? null;

        return [
            'status_label' => $token['label'],
            'status_badge' => $token['badge'],
            'details' => is_array($security['headers_details'] ?? null) ? $security['headers_details'] : [],
            'reports' => [
                'last_24h' => $last === null ? 'n/a' : (string) $last,
                'total' => $total === null ? 'n/a' : (string) $total,
            ],
        ];
    }

    private function decoratePreflight(array $preflight): array
    {
        $env = is_array($preflight['env'] ?? null) ? $preflight['env'] : [];

        return [
            'commands' => is_array($preflight['commands'] ?? null) ? $preflight['commands'] : [],
            'env' => [
                'app_env' => (string) ($env['app_env'] ?? ''),
                'app_debug' => (bool) ($env['app_debug'] ?? false),
                'read_only' => (bool) ($env['read_only'] ?? false),
                'headless' => (bool) ($env['headless'] ?? false),
                'storage_disk' => (string) ($env['storage_disk'] ?? ''),
            ],
        ];
    }

    private function countSecurityReports(): array
    {
        try {
            $total = $this->securityReports->count([]);
        } catch (Throwable) {
            return ['last_24h' => null, 'total' => null];
        }

        try {
            $cutoff = (new DateTimeImmutable('-24 hours'))->format('Y-m-d H:i:s');
            $recent = $this->securityReports->count(['since' => $cutoff]);
        } catch (Throwable) {
            $recent = null;
        }

        return [
            'last_24h' => $recent,
            'total' => $total,
        ];
    }

    private function latestBackup(string $dir): array
    {
        if (!is_dir($dir)) {
            return ['name' => null, 'created_at' => null];
        }

        $files = glob(rtrim($dir, '/\\') . '/laas_backup_*_v2.tar.gz') ?: [];
        if ($files === []) {
            return ['name' => null, 'created_at' => null];
        }

        rsort($files, SORT_STRING);
        $name = basename((string) $files[0]);
        $createdAt = null;
        if (preg_match('/laas_backup_(\d{8})_(\d{6})_v2\.tar\.gz/', $name, $m)) {
            $date = $m[1];
            $time = $m[2];
            $createdAt = substr($date, 0, 4) . '-' . substr($date, 4, 2) . '-' . substr($date, 6, 2)
                . ' ' . substr($time, 0, 2) . ':' . substr($time, 2, 2) . ':' . substr($time, 4, 2);
        }

        return [
            'name' => $name,
            'created_at' => $createdAt,
        ];
    }

    private function splitLines(string $value): array
    {
        $lines = preg_split('/\r\n|\r|\n/', $value) ?: [];
        $out = [];
        foreach ($lines as $line) {
            $line = trim((string) $line);
            if ($line !== '') {
                $out[] = $line;
            }
        }
        return $out;
    }

    private function statusFromBool(bool $value): string
    {
        return $value ? 'ok' : 'fail';
    }

    private function statusFromCode(int $code): string
    {
        if ($code === 1) {
            return 'fail';
        }
        if ($code === 2) {
            return 'warn';
        }
        return 'ok';
    }

    /** @return array{label: string, badge: string} */
    private function statusToken(string $status): array
    {
        $status = strtolower($status);
        return match ($status) {
            'ok' => ['label' => 'OK', 'badge' => 'success'],
            'fail' => ['label' => 'FAIL', 'badge' => 'danger'],
            default => ['label' => 'WARN', 'badge' => 'warning'],
        };
    }

    private function envInt(string $key, int $default): int
    {
        $value = $_ENV[$key] ?? getenv($key) ?: null;
        if ($value === null || $value === '') {
            return $default;
        }
        if (!is_numeric($value)) {
            return $default;
        }
        return (int) $value;
    }
}
