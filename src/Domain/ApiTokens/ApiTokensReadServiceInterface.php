<?php
declare(strict_types=1);

namespace Laas\Domain\ApiTokens;

interface ApiTokensReadServiceInterface
{
    /** @return array<int, array<string, mixed>> */
    public function listTokens(?int $userId = null, int $limit = 100, int $offset = 0): array;

    public function countTokens(?int $userId = null): int;

    /** @return array<int, string> */
    public function allowedScopes(): array;

    /** @return array<int, string> */
    public function defaultScopesSelection(): array;
}
