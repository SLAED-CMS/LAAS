<?php
declare(strict_types=1);

use Laas\Core\FeatureFlags;
use Laas\Core\FeatureFlagsInterface;
use Laas\Core\Kernel;
use Laas\Http\Request;
use Laas\Session\NativeSession;
/**
 * Admin HTML smoke checks.
 *
 * Usage:
 *   php tools/cli.php admin:smoke [--fixture=/path/to/fixture.php]
 */

if (!class_exists(FeatureFlags::class) && is_file(__DIR__ . '/../vendor/autoload.php')) {
    require_once __DIR__ . '/../vendor/autoload.php';
}

/**
 * @param array<int, string> $args
 */
function admin_smoke_run(string $rootPath, array $args = []): int
{
    $root = admin_smoke_root_path($rootPath);
    $fixturePath = (string) (admin_smoke_get_option($args, 'fixture') ?? ($_ENV['ADMIN_SMOKE_FIXTURE'] ?? ''));
    $fixture = $fixturePath !== '' ? admin_smoke_load_fixture($fixturePath) : null;
    if ($fixturePath !== '' && $fixture === null) {
        echo "admin.smoke.fail fixture unable_to_load\n";
        return 1;
    }

    $featureConfig = admin_smoke_load_config($root . '/config/admin_features.php') ?? [];
    $flags = new FeatureFlags($featureConfig);
    $appConfig = admin_smoke_load_config($root . '/config/app.php') ?? [];
    $themeDebug = (bool) ($appConfig['debug'] ?? false);

    $features = [
        'palette' => $flags->isEnabled(FeatureFlagsInterface::ADMIN_FEATURE_PALETTE),
        'blocks_studio' => $flags->isEnabled(FeatureFlagsInterface::ADMIN_FEATURE_BLOCKS_STUDIO),
        'theme_inspector' => $flags->isEnabled(FeatureFlagsInterface::ADMIN_FEATURE_THEME_INSPECTOR),
        'headless_playground' => $flags->isEnabled(FeatureFlagsInterface::ADMIN_FEATURE_HEADLESS_PLAYGROUND),
    ];

    $restoreEnv = null;
    if ($fixture !== null) {
        $sender = admin_smoke_fixture_sender($fixture);
    } else {
        [$sender, $restoreEnv] = admin_smoke_kernel_sender($root);
    }

    $checks = [
        [
            'label' => 'admin.status',
            'method' => 'GET',
            'path' => '/admin',
            'expected_status' => 200,
            'check' => static function (array $response) use ($features): ?string {
                $body = (string) ($response['body'] ?? '');
                $marker = 'data-palette-open="1"';
                if ($features['palette'] && !str_contains($body, $marker)) {
                    return 'palette_marker_missing';
                }
                if (!$features['palette'] && str_contains($body, $marker)) {
                    return 'palette_marker_present';
                }
                return null;
            },
        ],
        [
            'label' => 'admin.modules.status',
            'method' => 'GET',
            'path' => '/admin/modules',
            'expected_status' => 200,
            'check' => static function (array $response): ?string {
                $body = (string) ($response['body'] ?? '');
                if (!str_contains($body, 'Modules')) {
                    return 'missing_modules_header';
                }
                if (!str_contains($body, 'id="module-')) {
                    return 'missing_module_row';
                }
                return null;
            },
        ],
        [
            'label' => 'admin.pages.status',
            'method' => 'GET',
            'path' => '/admin/pages/1/edit',
            'expected_status' => 200,
            'check' => static function (array $response) use ($features): ?string {
                $body = (string) ($response['body'] ?? '');
                $marker = 'Blocks (JSON)';
                if ($features['blocks_studio'] && !str_contains($body, $marker)) {
                    return 'blocks_marker_missing';
                }
                if (!$features['blocks_studio'] && str_contains($body, $marker)) {
                    return 'blocks_marker_present';
                }
                return null;
            },
        ],
        [
            'label' => 'admin.themes.status',
            'method' => 'GET',
            'path' => '/admin/themes',
            'expected_status' => $features['theme_inspector'] ? 200 : 404,
            'check' => static function (array $response) use ($features, $themeDebug): ?string {
                $body = (string) ($response['body'] ?? '');
                $marker = 'data-theme-validate="1"';
                if ($features['theme_inspector'] && $themeDebug && !str_contains($body, $marker)) {
                    return 'validate_marker_missing';
                }
                if (!$features['theme_inspector'] && str_contains($body, $marker)) {
                    return 'validate_marker_present';
                }
                return null;
            },
            'expect_no_store' => $features['theme_inspector'],
        ],
        [
            'label' => 'admin.headless.status',
            'method' => 'GET',
            'path' => '/admin/headless-playground',
            'expected_status' => $features['headless_playground'] ? 200 : 404,
            'check' => static function (array $response) use ($features): ?string {
                $body = (string) ($response['body'] ?? '');
                $formMarker = 'data-headless-form="1"';
                $resultMarker = 'data-headless-result="1"';
                if ($features['headless_playground']) {
                    if (!str_contains($body, $formMarker)) {
                        return 'form_marker_missing';
                    }
                    if (!str_contains($body, $resultMarker)) {
                        return 'result_marker_missing';
                    }
                }
                if (!$features['headless_playground']) {
                    if (str_contains($body, $formMarker) || str_contains($body, $resultMarker)) {
                        return 'headless_marker_present';
                    }
                }
                return null;
            },
            'expect_no_store' => $features['headless_playground'],
        ],
    ];

    foreach ($checks as $check) {
        $response = $sender($check['method'], $check['path']);
        $status = (int) ($response['status'] ?? 0);
        $expectedStatus = (int) ($check['expected_status'] ?? 200);
        if (!admin_smoke_assert($status === $expectedStatus, $check['label'], 'status expected ' . $expectedStatus . ' got ' . $status)) {
            if (is_callable($restoreEnv)) {
                $restoreEnv();
            }
            return 1;
        }

        $contentType = trim(admin_smoke_header($response['headers'] ?? [], 'content-type'));
        $expectedType = 'text/html; charset=utf-8';
        if (!admin_smoke_assert(strtolower($contentType) === $expectedType, $check['label'] . '.content_type', 'expected ' . $expectedType)) {
            if (is_callable($restoreEnv)) {
                $restoreEnv();
            }
            return 1;
        }

        $noStore = (bool) ($check['expect_no_store'] ?? false);
        if ($noStore) {
            $cacheControl = admin_smoke_header($response['headers'] ?? [], 'cache-control');
            if (!admin_smoke_assert(str_contains(strtolower($cacheControl), 'no-store'), $check['label'] . '.no_store', 'cache-control missing no-store')) {
                if (is_callable($restoreEnv)) {
                    $restoreEnv();
                }
                return 1;
            }
        }

        $secretsIssue = admin_smoke_secrets_issue((string) ($response['body'] ?? ''));
        if ($secretsIssue !== '') {
            if (!admin_smoke_assert(false, $check['label'] . '.secrets', $secretsIssue)) {
                if (is_callable($restoreEnv)) {
                    $restoreEnv();
                }
                return 1;
            }
        } else {
            admin_smoke_assert(true, $check['label'] . '.secrets', '');
        }

        $customCheck = $check['check'] ?? null;
        if (is_callable($customCheck)) {
            $issue = $customCheck($response);
            if ($issue !== null) {
                if (!admin_smoke_assert(false, $check['label'] . '.markers', $issue)) {
                    if (is_callable($restoreEnv)) {
                        $restoreEnv();
                    }
                    return 1;
                }
            } else {
                admin_smoke_assert(true, $check['label'] . '.markers', '');
            }
        }
    }

    if (is_callable($restoreEnv)) {
        $restoreEnv();
    }

    return 0;
}

function admin_smoke_root_path(string $rootPath): string
{
    $rootPath = rtrim($rootPath, '/\\');
    if ($rootPath === '') {
        $rootPath = dirname(__DIR__);
    }
    $real = realpath($rootPath);
    return $real !== false ? $real : $rootPath;
}

/**
 * @param array<int, string> $args
 */
function admin_smoke_get_option(array $args, string $name): ?string
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

/**
 * @return null|array<string, mixed>
 */
function admin_smoke_load_config(string $path): ?array
{
    if (!is_file($path)) {
        return null;
    }
    $config = require $path;
    return is_array($config) ? $config : null;
}

/**
 * @return null|array<string, array{status: int, headers: array<int|string, string>, body: string}>
 */
function admin_smoke_load_fixture(string $path): ?array
{
    if (!is_file($path)) {
        return null;
    }
    $data = require $path;
    return is_array($data) ? $data : null;
}

/**
 * @param array<string, array{status: int, headers: array<int|string, string>, body: string}> $fixture
 */
function admin_smoke_fixture_sender(array $fixture): callable
{
    return static function (string $method, string $path) use ($fixture): array {
        $key = $path;
        if (!isset($fixture[$key])) {
            return [
                'status' => 500,
                'headers' => ['content-type' => 'text/html; charset=utf-8'],
                'body' => 'fixture_missing_response',
            ];
        }
        $response = $fixture[$key];
        return [
            'status' => (int) ($response['status'] ?? 0),
            'headers' => is_array($response['headers'] ?? null) ? $response['headers'] : [],
            'body' => (string) ($response['body'] ?? ''),
        ];
    };
}

/**
 * @return array{0: callable, 1: callable}
 */
function admin_smoke_kernel_sender(string $rootPath): array
{
    $restoreEnv = admin_smoke_force_sqlite_env();

    $kernel = new Kernel($rootPath);
    $pdo = $kernel->database()->pdo();
    admin_smoke_seed_sqlite($pdo);

    $session = new NativeSession();
    $session->start();
    $session->set('user_id', 1);
    $_SERVER['REMOTE_ADDR'] = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';

    $sender = static function (string $method, string $path) use ($kernel, $session): array {
        $parts = parse_url($path);
        $routePath = is_array($parts) && isset($parts['path']) ? (string) $parts['path'] : $path;
        $query = [];
        if (is_array($parts) && isset($parts['query'])) {
            parse_str((string) $parts['query'], $query);
        }

        $request = new Request(
            strtoupper($method),
            $routePath,
            is_array($query) ? $query : [],
            [],
            [
                'accept' => 'text/html',
                'host' => 'admin-smoke.local',
            ],
            '',
            $session
        );

        $response = $kernel->handle($request);
        return [
            'status' => $response->getStatus(),
            'headers' => $response->getHeaders(),
            'body' => $response->getBody(),
        ];
    };

    return [$sender, $restoreEnv];
}

function admin_smoke_force_sqlite_env(): callable
{
    $keys = [
        'DB_DRIVER',
        'DB_DATABASE',
        'DB_NAME',
        'DB_HOST',
        'DB_USER',
        'DB_USERNAME',
        'DB_PASSWORD',
        'DB_PORT',
    ];
    $previous = [];
    foreach ($keys as $key) {
        $previous[$key] = $_ENV[$key] ?? null;
    }

    $_ENV['DB_DRIVER'] = 'sqlite';
    $_ENV['DB_DATABASE'] = ':memory:';
    $_ENV['DB_NAME'] = ':memory:';
    $_ENV['DB_HOST'] = '';
    $_ENV['DB_USER'] = '';
    $_ENV['DB_USERNAME'] = '';
    $_ENV['DB_PASSWORD'] = '';
    $_ENV['DB_PORT'] = '';
    foreach ($keys as $key) {
        $value = $_ENV[$key] ?? '';
        putenv($key . '=' . $value);
    }

    return static function () use ($keys, $previous): void {
        foreach ($keys as $key) {
            $value = $previous[$key] ?? null;
            if ($value === null || $value === '') {
                unset($_ENV[$key]);
                putenv($key);
            } else {
                $_ENV[$key] = $value;
                putenv($key . '=' . $value);
            }
        }
    };
}

function admin_smoke_seed_sqlite(PDO $pdo): void
{
    $pdo->exec('CREATE TABLE IF NOT EXISTS users (id INTEGER PRIMARY KEY AUTOINCREMENT, username TEXT, email TEXT, password_hash TEXT, status INTEGER, last_login_at TEXT, last_login_ip TEXT, totp_secret TEXT, totp_enabled INTEGER DEFAULT 0, backup_codes TEXT, created_at TEXT, updated_at TEXT)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS roles (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, title TEXT, created_at TEXT, updated_at TEXT)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS permissions (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, title TEXT, created_at TEXT, updated_at TEXT)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS role_user (user_id INTEGER, role_id INTEGER)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS permission_role (role_id INTEGER, permission_id INTEGER)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS settings (id INTEGER PRIMARY KEY AUTOINCREMENT, `key` VARCHAR(255) UNIQUE, `value` TEXT NULL, `type` VARCHAR(20) NULL, updated_at DATETIME NULL)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS modules (name TEXT PRIMARY KEY, enabled INTEGER NOT NULL DEFAULT 0, version TEXT NULL, installed_at TEXT NULL, updated_at TEXT NOT NULL)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS pages (id INTEGER PRIMARY KEY AUTOINCREMENT, title TEXT, slug TEXT, status TEXT, content TEXT, created_at TEXT, updated_at TEXT)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS pages_revisions (id INTEGER PRIMARY KEY AUTOINCREMENT, page_id INTEGER NOT NULL, blocks_json TEXT, created_at TEXT, created_by INTEGER)');

    $now = '2026-01-01 00:00:00';
    $pdo->exec("INSERT OR IGNORE INTO users (id, username, email, password_hash, status, created_at, updated_at) VALUES (1, 'admin', 'admin@example.com', 'hash', 1, '{$now}', '{$now}')");
    $pdo->exec("INSERT OR IGNORE INTO roles (id, name, title, created_at, updated_at) VALUES (1, 'admin', 'Admin', '{$now}', '{$now}')");
    $pdo->exec("INSERT OR IGNORE INTO permissions (id, name, title, created_at, updated_at) VALUES (1, 'admin.access', 'Admin access', '{$now}', '{$now}')");
    $pdo->exec("INSERT OR IGNORE INTO permissions (id, name, title, created_at, updated_at) VALUES (2, 'pages.edit', 'Pages edit', '{$now}', '{$now}')");
    $pdo->exec("INSERT OR IGNORE INTO permissions (id, name, title, created_at, updated_at) VALUES (3, 'admin.modules.manage', 'Modules manage', '{$now}', '{$now}')");
    $pdo->exec("INSERT OR IGNORE INTO permissions (id, name, title, created_at, updated_at) VALUES (4, 'admin.settings.manage', 'Settings manage', '{$now}', '{$now}')");
    $pdo->exec('INSERT OR IGNORE INTO role_user (user_id, role_id) VALUES (1, 1)');
    $pdo->exec('INSERT OR IGNORE INTO permission_role (role_id, permission_id) VALUES (1, 1)');
    $pdo->exec('INSERT OR IGNORE INTO permission_role (role_id, permission_id) VALUES (1, 2)');
    $pdo->exec('INSERT OR IGNORE INTO permission_role (role_id, permission_id) VALUES (1, 3)');
    $pdo->exec('INSERT OR IGNORE INTO permission_role (role_id, permission_id) VALUES (1, 4)');

    $pdo->exec("INSERT OR IGNORE INTO pages (id, title, slug, status, content, created_at, updated_at) VALUES (1, 'Sample', 'sample', 'draft', '', '{$now}', '{$now}')");
}

function admin_smoke_header(array $headers, string $name): string
{
    $wanted = strtolower($name);
    foreach ($headers as $key => $value) {
        if (strtolower((string) $key) === $wanted) {
            return (string) $value;
        }
    }
    return '';
}

function admin_smoke_secrets_issue(string $body): string
{
    foreach (['APP_KEY', 'DB_PASSWORD'] as $needle) {
        if (str_contains($body, $needle)) {
            return 'secret_marker_found:' . $needle;
        }
    }
    return '';
}

function admin_smoke_assert(bool $ok, string $label, string $reason): bool
{
    if ($ok) {
        echo 'admin.smoke.ok ' . $label . "\n";
        return true;
    }

    echo 'admin.smoke.fail ' . $label;
    if ($reason !== '') {
        echo ' ' . $reason;
    }
    echo "\n";
    return false;
}

if (PHP_SAPI === 'cli' && realpath($argv[0] ?? '') === realpath(__FILE__)) {
    $args = $argv;
    array_shift($args);
    exit(admin_smoke_run(dirname(__DIR__), $args));
}
