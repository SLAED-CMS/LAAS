<?php
declare(strict_types=1);

namespace Laas\Support;

use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use Psr\Log\LoggerInterface;
use RuntimeException;

final class LoggerFactory
{
    public function __construct(private string $rootPath)
    {
    }

    public function create(array $config): LoggerInterface
    {
        $logDir = $this->rootPath . '/storage/logs';
        if (!is_dir($logDir) && !mkdir($logDir, 0775, true) && !is_dir($logDir)) {
            throw new RuntimeException('Unable to create log directory: ' . $logDir);
        }

        $debug = (bool) ($config['debug'] ?? false);
        $level = $debug ? Logger::DEBUG : Logger::WARNING;

        $logger = new Logger('app');
        $logger->pushHandler(new StreamHandler($logDir . '/app.log', $level));

        return $logger;
    }
}
