<?php
declare(strict_types=1);

namespace Laas\Domain\Ops;

use Laas\Domain\Support\ReadOnlyProxy;

final class OpsReadServiceProxy extends ReadOnlyProxy implements OpsReadServiceInterface
{
    /** @return array<string, mixed> */
    public function overview(bool $isHttps): array
    {
        return $this->call(__FUNCTION__, func_get_args());
    }

    /** @return array<string, mixed> */
    public function viewData(array $snapshot, callable $translate): array
    {
        return $this->call(__FUNCTION__, func_get_args());
    }
}
