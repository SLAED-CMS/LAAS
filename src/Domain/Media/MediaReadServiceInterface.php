<?php
declare(strict_types=1);

namespace Laas\Domain\Media;

interface MediaReadServiceInterface
{
    /** @return array<string, mixed>|null */
    public function find(int $id): ?array;

    /** @return array<int, array<string, mixed>> */
    public function list(int $limit = 20, int $offset = 0, string $query = ''): array;

    public function count(string $query = ''): int;

    /** @return array<int, array<string, mixed>> */
    public function listPublic(int $limit = 20, int $offset = 0, string $query = ''): array;

    public function countPublic(string $query = ''): int;

    /** @return array<int, array<string, mixed>> */
    public function search(string $query, int $limit = 10, int $offset = 0): array;

    public function countSearch(string $query): int;
}
