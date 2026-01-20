<?php
declare(strict_types=1);

namespace Laas\Domain\Security;

interface SecurityReportsServiceInterface
{
    /** @return array<int, array<string, mixed>> */
    public function list(array $filters = []): array;

    public function count(array $filters = []): int;
}
