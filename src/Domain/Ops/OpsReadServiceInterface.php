<?php

declare(strict_types=1);

namespace Laas\Domain\Ops;

interface OpsReadServiceInterface
{
    /** @return array<string, mixed> */
    public function overview(bool $isHttps): array;

    /** @return array<string, mixed> */
    public function viewData(array $snapshot, callable $translate): array;
}
