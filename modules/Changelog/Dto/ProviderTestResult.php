<?php
declare(strict_types=1);

namespace Laas\Modules\Changelog\Dto;

final class ProviderTestResult
{
    public function __construct(
        public bool $ok,
        public string $message
    ) {
    }
}
