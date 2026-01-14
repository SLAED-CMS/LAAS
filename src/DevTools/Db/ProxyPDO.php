<?php
declare(strict_types=1);

namespace Laas\DevTools\Db;

use Laas\DevTools\DevToolsContext;
use Laas\Database\DbProfileCollector;
use PDO;
use PDOStatement;

final class ProxyPDO extends PDO
{
    public function __construct(
        string $dsn,
        string $username,
        string $password,
        array $options,
        private ?DevToolsContext $context,
        private bool $collectDb,
        private ?DbProfileCollector $profileCollector = null
    ) {
        parent::__construct($dsn, $username, $password, $options);

        $this->setAttribute(PDO::ATTR_STATEMENT_CLASS, [
            ProxyPDOStatement::class,
            [$this->context, $this->collectDb, $this->profileCollector],
        ]);
    }

    public function prepare($statement, $options = []): PDOStatement|false
    {
        return parent::prepare($statement, $options);
    }

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        $start = microtime(true);
        $result = parent::query($query, $fetchMode, ...$fetchModeArgs);
        $this->recordQuery($query, 0, $start);

        return $result;
    }

    public function exec(string $statement): int|false
    {
        $start = microtime(true);
        $result = parent::exec($statement);
        $this->recordQuery($statement, 0, $start);

        return $result;
    }

    private function recordQuery(string $sql, int $paramsCount, float $start): void
    {
        $durationMs = (microtime(true) - $start) * 1000;
        if ($this->collectDb && $this->context !== null) {
            $this->context->addDbQuery($sql, $paramsCount, $durationMs);
        }
        if ($this->profileCollector !== null) {
            $this->profileCollector->addQuery($sql, $paramsCount, $durationMs);
        }
    }
}
