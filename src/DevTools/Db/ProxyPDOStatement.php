<?php

declare(strict_types=1);

namespace Laas\DevTools\Db;

use Laas\Database\DbProfileCollector;
use Laas\DevTools\DevToolsContext;
use PDOStatement;

final class ProxyPDOStatement extends PDOStatement
{
    private ?DevToolsContext $context = null;
    private bool $collectDb = false;
    private ?DbProfileCollector $profileCollector = null;

    protected function __construct(?DevToolsContext $context, bool $collectDb, ?DbProfileCollector $profileCollector = null)
    {
        $this->context = $context;
        $this->collectDb = $collectDb;
        $this->profileCollector = $profileCollector;
    }

    public function execute($params = null): bool
    {
        $start = microtime(true);
        $result = parent::execute($params);

        $count = is_array($params) ? count($params) : 0;
        $durationMs = (microtime(true) - $start) * 1000;
        if ($this->collectDb && $this->context !== null) {
            $this->context->addDbQuery((string) ($this->queryString ?? ''), $count, $durationMs);
        }
        if ($this->profileCollector !== null) {
            $this->profileCollector->addQuery((string) ($this->queryString ?? ''), $count, $durationMs);
        }

        return $result;
    }
}
