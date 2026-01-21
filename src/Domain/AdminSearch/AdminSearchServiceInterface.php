<?php
declare(strict_types=1);

namespace Laas\Domain\AdminSearch;

interface AdminSearchServiceInterface
{
    /** @return array<string, mixed> */
    public function search(string $q, array $opts = []): array;

    /** @return array<int, array<string, mixed>> */
    public function commands(): array;
}
