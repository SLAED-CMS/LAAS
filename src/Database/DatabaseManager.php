<?php
declare(strict_types=1);

namespace Laas\Database;

use Laas\DevTools\DevToolsContext;
use Laas\DevTools\Db\ProxyPDO;
use Laas\Support\RequestScope;
use PDO;

final class DatabaseManager
{
    private ?PDO $pdo = null;
    private ?DevToolsContext $devtoolsContext = null;
    private array $devtoolsConfig = [];

    public function __construct(private array $config)
    {
    }

    public function enableDevTools(DevToolsContext $context, array $config): void
    {
        $this->devtoolsContext = $context;
        $this->devtoolsConfig = $config;
    }

    public function getDevToolsContext(): ?DevToolsContext
    {
        return $this->devtoolsContext;
    }

    public function pdo(): PDO
    {
        if ($this->pdo !== null) {
            return $this->pdo;
        }

        $driver = $this->config['driver'] ?? 'mysql';
        if ($driver === 'sqlite') {
            $db = $this->config['database'] ?? ':memory:';
            $this->ensureSqliteDir($db);
            $dsn = 'sqlite:' . $db;
            $options = $this->config['options'] ?? [];
            $options[PDO::ATTR_ERRMODE] = PDO::ERRMODE_EXCEPTION;
            $options[PDO::ATTR_DEFAULT_FETCH_MODE] = PDO::FETCH_ASSOC;
            $options[PDO::ATTR_EMULATE_PREPARES] = false;

            $this->pdo = new PDO($dsn, null, null, $options);
            return $this->pdo;
        }

        $host = $this->config['host'] ?? '127.0.0.1';
        $port = (int) ($this->config['port'] ?? 3306);
        $db = $this->config['database'] ?? '';
        $charset = $this->config['charset'] ?? 'utf8mb4';

        $dsn = sprintf('%s:host=%s;port=%d;dbname=%s;charset=%s', $driver, $host, $port, $db, $charset);

        $options = $this->config['options'] ?? [];
        $options[PDO::ATTR_ERRMODE] = PDO::ERRMODE_EXCEPTION;
        $options[PDO::ATTR_DEFAULT_FETCH_MODE] = PDO::FETCH_ASSOC;
        $options[PDO::ATTR_EMULATE_PREPARES] = false;

        $collectDb = (bool) ($this->devtoolsConfig['collect_db'] ?? false);
        $devtoolsEnabled = (bool) ($this->devtoolsConfig['enabled'] ?? false);
        if ($devtoolsEnabled && $collectDb && class_exists(ProxyPDO::class)) {
            $this->pdo = new ProxyPDO(
                $dsn,
                (string) ($this->config['username'] ?? ''),
                (string) ($this->config['password'] ?? ''),
                $options,
                $this->devtoolsContext,
                $collectDb
            );
            return $this->pdo;
        }

        $this->pdo = new PDO(
            $dsn,
            (string) ($this->config['username'] ?? ''),
            (string) ($this->config['password'] ?? ''),
            $options
        );

        return $this->pdo;
    }

    public function healthCheck(): bool
    {
        if (RequestScope::has('db.healthcheck')) {
            $cached = RequestScope::get('db.healthcheck');
            return $cached === true;
        }

        try {
            $stmt = $this->pdo()->query('SELECT 1');
            $ok = $stmt !== false;
            RequestScope::set('db.healthcheck', $ok);
            return $ok;
        } catch (\Throwable $e) {
            // Debug output for CI/test environments
            if (getenv('CI') === 'true' || getenv('APP_ENV') === 'test') {
                fwrite(STDERR, "DEBUG: DatabaseManager::healthCheck() exception: " . $e->getMessage() . "\n");
                fwrite(STDERR, "DEBUG: Database config driver: " . ($this->config['driver'] ?? 'none') . "\n");
                fwrite(STDERR, "DEBUG: Database config database: " . ($this->config['database'] ?? 'none') . "\n");
                fwrite(STDERR, "DEBUG: PDO is null: " . ($this->pdo === null ? 'yes' : 'no') . "\n");
            }
            RequestScope::set('db.healthcheck', false);
            return false;
        }
    }

    private function ensureSqliteDir(string $db): void
    {
        if ($db === ':memory:' || str_starts_with($db, 'file:')) {
            return;
        }

        $dir = dirname($db);
        if ($dir === '' || $dir === '.') {
            return;
        }

        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        if (!is_file($db)) {
            $handle = @fopen($db, 'c');
            if (is_resource($handle)) {
                fclose($handle);
            }
        }
    }
}
