<?php
declare(strict_types=1);

namespace Laas\Modules\Changelog\Dto;

final class ChangelogPage
{
    /** @param array<int, ChangelogCommit> $commits */
    public function __construct(
        public array $commits,
        public int $page,
        public int $perPage,
        public bool $hasMore
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $items = [];
        foreach ($this->commits as $commit) {
            $items[] = $commit->toArray();
        }

        return [
            'commits' => $items,
            'page' => $this->page,
            'per_page' => $this->perPage,
            'has_more' => $this->hasMore,
        ];
    }
}
