<?php
declare(strict_types=1);

namespace Laas\DevTools\Db;

use Laas\DevTools\DevToolsContext;
use PDOStatement;

final class ProxyPDOStatement extends PDOStatement
{
    private ?DevToolsContext $context = null;
    private bool $collectDb = false;

    protected function __construct(?DevToolsContext $context, bool $collectDb)
    {
        $this->context = $context;
        $this->collectDb = $collectDb;
    }

    public function execute($params = null): bool
    {
        $start = microtime(true);
        $result = parent::execute($params);

        if ($this->collectDb && $this->context !== null) {
            $count = is_array($params) ? count($params) : 0;
            $durationMs = (microtime(true) - $start) * 1000;
            $this->context->addDbQuery((string) ($this->queryString ?? ''), $count, $durationMs);
        }

        return $result;
    }
}
