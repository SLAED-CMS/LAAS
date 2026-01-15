<?php
declare(strict_types=1);

namespace Laas\Support;

final class UrlValidationResult
{
    public function __construct(
        private bool $ok,
        private string $reason = ''
    ) {
    }

    public function ok(): bool
    {
        return $this->ok;
    }

    public function reason(): string
    {
        return $this->reason;
    }
}
