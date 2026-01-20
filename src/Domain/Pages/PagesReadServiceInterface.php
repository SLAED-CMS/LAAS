<?php
declare(strict_types=1);

namespace Laas\Domain\Pages;

interface PagesReadServiceInterface
{
    /** @return array<int, array<string, mixed>> */
    public function list(array $filters = []): array;

    /** @return array<int, array<string, mixed>> */
    public function listPublishedAll(): array;

    public function count(array $filters = []): int;

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array;

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
}
