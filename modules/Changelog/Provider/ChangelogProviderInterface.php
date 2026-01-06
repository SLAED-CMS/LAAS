<?php
declare(strict_types=1);

namespace Laas\Modules\Changelog\Provider;

use Laas\Modules\Changelog\Dto\ChangelogPage;
use Laas\Modules\Changelog\Dto\ProviderTestResult;

interface ChangelogProviderInterface
{
    /** @param array<string, string> $filters */
    public function fetchCommits(string $branch, int $limit, int $page, bool $includeMerges, array $filters = []): ChangelogPage;
    public function testConnection(): ProviderTestResult;
}
