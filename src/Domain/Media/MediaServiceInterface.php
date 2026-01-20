<?php
declare(strict_types=1);

namespace Laas\Domain\Media;

interface MediaServiceInterface
{
    /**
     * @param array{name: string, tmp_path: string, size: int, mime: string} $file
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public function upload(array $file, array $options = []): array;

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

    /** @mutation */
    public function delete(int $id): void;

    /** @mutation */
    public function setPublic(int $id, bool $isPublic, ?string $token): void;
}
