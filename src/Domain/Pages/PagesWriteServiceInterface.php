<?php
declare(strict_types=1);

namespace Laas\Domain\Pages;

interface PagesWriteServiceInterface
{
    /**
     * @return array<string, mixed>
     * @mutation
     */
    public function create(array $data): array;

    /** @mutation */
    public function update(int $id, array $data): void;

    /** @mutation */
    public function updateStatus(int $id, string $status): void;

    /** @mutation */
    public function delete(int $id): void;

    /**
     * @param array<int, array<string, mixed>> $blocks
     * @mutation
     */
    public function createRevision(int $pageId, array $blocks, ?int $createdBy): int;

    /** @mutation */
    public function deleteRevisionsByPageId(int $pageId): void;
}
