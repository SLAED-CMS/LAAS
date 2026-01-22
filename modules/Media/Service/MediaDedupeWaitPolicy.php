<?php

declare(strict_types=1);

namespace Laas\Modules\Media\Service;

final class MediaDedupeWaitPolicy
{
    public function __construct(
        private int $maxWaitMs,
        private int $initialBackoffMs,
        private int $maxBackoffMs,
        private int $jitterMs
    ) {
        $this->maxWaitMs = max(0, $this->maxWaitMs);
        $this->initialBackoffMs = max(1, $this->initialBackoffMs);
        $this->maxBackoffMs = max($this->initialBackoffMs, $this->maxBackoffMs);
        $this->jitterMs = max(0, $this->jitterMs);
    }

    public static function fromConfig(array $config): self
    {
        return new self(
            (int) ($config['dedupe_wait_max_ms'] ?? 10000),
            (int) ($config['dedupe_wait_initial_backoff_ms'] ?? 50),
            (int) ($config['dedupe_wait_max_backoff_ms'] ?? 250),
            (int) ($config['dedupe_wait_jitter_ms'] ?? 20)
        );
    }

    public function maxWaitMs(): int
    {
        return $this->maxWaitMs;
    }

    public function initialBackoffMs(): int
    {
        return $this->initialBackoffMs;
    }

    public function maxBackoffMs(): int
    {
        return $this->maxBackoffMs;
    }

    public function jitterMs(): int
    {
        return $this->jitterMs;
    }
}
