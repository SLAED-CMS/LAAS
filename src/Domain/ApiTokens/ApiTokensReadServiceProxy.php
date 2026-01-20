<?php
declare(strict_types=1);

namespace Laas\Domain\ApiTokens;

use Laas\Domain\Support\ReadOnlyProxy;

final class ApiTokensReadServiceProxy extends ReadOnlyProxy implements ApiTokensReadServiceInterface
{
    /** @return array<int, array<string, mixed>> */
    public function listTokens(?int $userId = null, int $limit = 100, int $offset = 0): array
    {
        return $this->call(__FUNCTION__, func_get_args());
    }

    public function countTokens(?int $userId = null): int
    {
        return $this->call(__FUNCTION__, func_get_args());
    }

    /** @return array<int, string> */
    public function allowedScopes(): array
    {
        return $this->call(__FUNCTION__, func_get_args());
    }

    /** @return array<int, string> */
    public function defaultScopesSelection(): array
    {
        return $this->call(__FUNCTION__, func_get_args());
    }
}
