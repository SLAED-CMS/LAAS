<?php
declare(strict_types=1);

namespace Laas\Database\Migrations;

use Laas\Database\DatabaseManager;
use PDO;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

final class Migrator
{
    private PDO $pdo;

    public function __construct(
        DatabaseManager $db,
        private string $rootPath,
        private array $moduleClasses,
        private array $context,
        private ?LoggerInterface $logger = null
    ) {
        $this->pdo = $db->pdo();
    }

    public function ensureMigrationsTable(): void
    {
        $driver = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'sqlite') {
            $sql = <<<SQL
CREATE TABLE IF NOT EXISTS migrations (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    migration VARCHAR(255) NOT NULL UNIQUE,
    batch INT NOT NULL,
    applied_at DATETIME NOT NULL
)
SQL;
        } else {
            $sql = <<<SQL
CREATE TABLE IF NOT EXISTS migrations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    migration VARCHAR(255) NOT NULL UNIQUE,
    batch INT NOT NULL,
    applied_at DATETIME NOT NULL
)
SQL;
        }
        $this->pdo->exec($sql);
    }

    /** @return array<string, string> migration => path */
    public function discoverMigrations(): array
    {
        $migrations = [];

        $coreDir = $this->rootPath . '/database/migrations/core';
        if (is_dir($coreDir)) {
            foreach (glob($coreDir . '/*.php') ?: [] as $file) {
                $name = basename($file, '.php');
                $migrations[$name] = $file;
            }
        }

        $moduleDirs = $this->moduleMigrationDirs();
        foreach ($moduleDirs as $dir) {
            foreach (glob($dir . '/*.php') ?: [] as $file) {
                $name = basename($file, '.php');
                $migrations[$name] = $file;
            }
        }

        ksort($migrations);

        return $migrations;
    }

    /** @return array<string, int> */
    public function appliedMigrations(): array
    {
        $this->ensureMigrationsTable();
        $stmt = $this->pdo->query('SELECT migration, batch FROM migrations');
        $rows = $stmt ? $stmt->fetchAll() : [];

        $applied = [];
        foreach ($rows as $row) {
            $applied[(string) $row['migration']] = (int) $row['batch'];
        }

        return $applied;
    }

    /** @return array<int, array{migration: string, applied: bool, batch: int|null}> */
    public function status(): array
    {
        $discovered = $this->discoverMigrations();
        $applied = $this->appliedMigrations();

        $status = [];
        foreach ($discovered as $name => $path) {
            $status[] = [
                'migration' => $name,
                'applied' => array_key_exists($name, $applied),
                'batch' => $applied[$name] ?? null,
            ];
        }

        return $status;
    }

    /** @return array<int, string> */
    public function up(int $steps = 0): array
    {
        $this->ensureMigrationsTable();
        $discovered = $this->discoverMigrations();
        $applied = $this->appliedMigrations();

        $pending = [];
        foreach ($discovered as $name => $path) {
            if (!array_key_exists($name, $applied)) {
                $pending[$name] = $path;
            }
        }

        if ($steps > 0) {
            $pending = array_slice($pending, 0, $steps, true);
        }

        if ($pending === []) {
            return [];
        }

        $batch = $this->nextBatch();
        $appliedNames = [];

        foreach ($pending as $name => $path) {
            $migration = $this->loadMigration($path);
            $this->runMigration(function () use ($migration): void {
                $migration->up($this->pdo);
            });

            $stmt = $this->pdo->prepare('INSERT INTO migrations (migration, batch, applied_at) VALUES (:migration, :batch, :applied_at)');
            $stmt->execute([
                'migration' => $name,
                'batch' => $batch,
                'applied_at' => $this->now(),
            ]);

            $appliedNames[] = $name;
        }

        return $appliedNames;
    }

    /** @return array<int, string> */
    public function down(int $steps = 1): array
    {
        $this->ensureMigrationsTable();
        $batches = $this->recentBatches($steps);
        if ($batches === []) {
            return [];
        }

        $stmt = $this->pdo->prepare('SELECT migration FROM migrations WHERE batch = :batch ORDER BY id DESC');
        $deleted = [];

        foreach ($batches as $batch) {
            $stmt->execute(['batch' => $batch]);
            $rows = $stmt->fetchAll();
            foreach ($rows as $row) {
                $name = (string) $row['migration'];
                $path = $this->findMigrationPath($name);
                if ($path === null) {
                    continue;
                }

                $migration = $this->loadMigration($path);
                $this->runMigration(function () use ($migration): void {
                    $migration->down($this->pdo);
                });

                $delStmt = $this->pdo->prepare('DELETE FROM migrations WHERE migration = :migration');
                $delStmt->execute(['migration' => $name]);
                $deleted[] = $name;
            }
        }

        return $deleted;
    }

    /** @return array<int, string> */
    public function refresh(): array
    {
        $rolled = [];
        while (true) {
            $batch = $this->recentBatches(1);
            if ($batch === []) {
                break;
            }

            $rolled = array_merge($rolled, $this->down(1));
        }

        $applied = $this->up(0);

        return array_merge($rolled, $applied);
    }

    private function nextBatch(): int
    {
        $stmt = $this->pdo->query('SELECT MAX(batch) AS max_batch FROM migrations');
        $row = $stmt ? $stmt->fetch() : null;
        $max = $row && $row['max_batch'] !== null ? (int) $row['max_batch'] : 0;

        return $max + 1;
    }

    /** @return array<int, int> */
    private function recentBatches(int $steps): array
    {
        $stmt = $this->pdo->query('SELECT DISTINCT batch FROM migrations ORDER BY batch DESC');
        $rows = $stmt ? $stmt->fetchAll() : [];
        $batches = array_map(static fn(array $row): int => (int) $row['batch'], $rows);

        if ($steps <= 0) {
            return $batches;
        }

        return array_slice($batches, 0, $steps);
    }

    private function findMigrationPath(string $name): ?string
    {
        $migrations = $this->discoverMigrations();

        return $migrations[$name] ?? null;
    }

    private function loadMigration(string $path): object
    {
        $context = $this->context;
        $migration = require $path;
        if (!is_object($migration) || !method_exists($migration, 'up') || !method_exists($migration, 'down')) {
            throw new RuntimeException('Invalid migration file: ' . $path);
        }

        return $migration;
    }

    private function runMigration(callable $callback): void
    {
        try {
            $callback();
        } catch (Throwable $e) {
            if ($this->logger !== null) {
                $this->logger->error($e->getMessage(), ['exception' => $e]);
            }
            throw $e;
        }
    }

    /** @return array<int, string> */
    private function moduleMigrationDirs(): array
    {
        $dirs = [];
        foreach ($this->moduleClasses as $class) {
            $parts = explode('\\', trim($class, '\\'));
            $moduleName = $parts[2] ?? null;
            if ($moduleName === null) {
                continue;
            }

            $dir = $this->rootPath . '/modules/' . $moduleName . '/migrations';
            if (is_dir($dir)) {
                $dirs[] = $dir;
            }
        }

        return $dirs;
    }

    private function now(): string
    {
        return (new \DateTimeImmutable())->format('Y-m-d H:i:s');
    }
}
