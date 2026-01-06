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

        $redact = static function (array $data): array {
            $mask = ['authorization', 'http_authorization', 'token', 'bearer'];
            foreach ($data as $key => $value) {
                $lower = strtolower((string) $key);
                if (in_array($lower, $mask, true)) {
                    $data[$key] = '[redacted]';
                }
            }
            return $data;
        };

        $logger->pushProcessor(static function ($record) use ($redact) {
            if ($record instanceof \Monolog\LogRecord) {
                return $record->with(
                    context: $redact($record->context),
                    extra: $redact($record->extra)
                );
            }

            if (is_array($record)) {
                if (isset($record['context']) && is_array($record['context'])) {
                    $record['context'] = $redact($record['context']);
                }
                if (isset($record['extra']) && is_array($record['extra'])) {
                    $record['extra'] = $redact($record['extra']);
                }
            }

            return $record;
        });

        return $logger;
    }
}
