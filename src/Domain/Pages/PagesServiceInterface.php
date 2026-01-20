<?php
declare(strict_types=1);

namespace Laas\Domain\Pages;

interface PagesServiceInterface
{
    /** @return array<int, array<string, mixed>> */
    public function list(array $filters = []): array;

    /** @return array<int, array<string, mixed>> */
    public function listPublishedAll(): array;

    public function count(array $filters = []): int;

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array;

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
     * @return array<int, array{type: string, data: array<string, mixed>}>
     */
    public function findLatestBlocks(int $pageId): array;

    /**
     * @return array<string, mixed>|null
     */
    public function findLatestRevision(int $pageId): ?array;

    public function findLatestRevisionId(int $pageId): int;

    /** @return array<int, int> */
    public function findLatestRevisionIds(array $pageIds): array;

    /**
     * @param array<int, array<string, mixed>> $blocks
     * @mutation
     */
    public function createRevision(int $pageId, array $blocks, ?int $createdBy): int;

    /** @mutation */
    public function deleteRevisionsByPageId(int $pageId): void;
}
