<?php
declare(strict_types=1);

use Laas\Support\HealthStatusTracker;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class HealthStatusTrackerTest extends TestCase
{
    public function testLogsDegradeOnceAndRecoveryOnce(): void
    {
        $root = sys_get_temp_dir() . '/laas_health_state_' . bin2hex(random_bytes(4));
        @mkdir($root . '/storage/health', 0775, true);

        $logger = new SpyLogger();
        $tracker = new HealthStatusTracker($root, $logger, 600);

        $tracker->logHealthTransition(false);
        $tracker->logHealthTransition(false);
        $tracker->logHealthTransition(true);
        $tracker->logHealthTransition(true);

        $this->assertSame(1, $logger->warnings);
        $this->assertSame(1, $logger->infos);
    }
}

final class SpyLogger implements LoggerInterface
{
    public int $warnings = 0;
    public int $infos = 0;

    public function emergency($message, array $context = []): void {}
    public function alert($message, array $context = []): void {}
    public function critical($message, array $context = []): void {}
    public function error($message, array $context = []): void {}
    public function warning($message, array $context = []): void { $this->warnings++; }
    public function notice($message, array $context = []): void {}
    public function info($message, array $context = []): void { $this->infos++; }
    public function debug($message, array $context = []): void {}
    public function log($level, $message, array $context = []): void {}
}
