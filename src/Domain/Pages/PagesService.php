<?php
declare(strict_types=1);

namespace Laas\Domain\Pages;

use InvalidArgumentException;
use Laas\Database\DatabaseManager;
use Laas\Modules\Pages\Repository\PagesRepository;
use Laas\Modules\Pages\Repository\PagesRevisionsRepository;
use Laas\Security\HtmlSanitizer;
use RuntimeException;
use Throwable;

class PagesService implements PagesServiceInterface
{
    private ?PagesRepository $repository = null;
    private ?PagesRevisionsRepository $revisions = null;

    public function __construct(private DatabaseManager $db)
    {
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        if ($id <= 0) {
            throw new InvalidArgumentException('Page id must be positive.');
        }

        return $this->repository()->findById($id);
    }

    public function count(array $filters = []): int
    {
        $status = $this->normalizeStatus($filters['status'] ?? 'all');
        $query = trim((string) ($filters['query'] ?? ''));
        $repo = $this->repository();

        $statusFilter = $status === 'all' ? null : $status;
        if ($query !== '') {
            return $repo->countSearch($query, $statusFilter);
        }

        return $repo->countByStatus($statusFilter);
    }

    /** @return array<int, array<string, mixed>> */
    public function list(array $filters = []): array
    {
        $limit = (int) ($filters['limit'] ?? 100);
        $offset = (int) ($filters['offset'] ?? 0);
        if ($limit <= 0) {
            throw new InvalidArgumentException('Limit must be positive.');
        }
        if ($offset < 0) {
            throw new InvalidArgumentException('Offset must be zero or positive.');
        }

        $status = $this->normalizeStatus($filters['status'] ?? 'all');
        $query = trim((string) ($filters['query'] ?? ''));
        $slug = trim((string) ($filters['slug'] ?? ''));

        $repo = $this->repository();

        if ($slug !== '') {
            $page = $status === 'published'
                ? $repo->findPublishedBySlug($slug)
                : $repo->findBySlug($slug);
            return $page === null ? [] : [$page];
        }

        $statusFilter = $status === 'all' ? null : $status;
        if ($query !== '') {
            return $repo->search($query, $limit, $offset, $statusFilter);
        }

        return $repo->listByStatus($statusFilter, $limit, $offset);
    }

    /**
     * @return array<string, mixed>
     * @mutation
     */
    public function create(array $data): array
    {
        $title = trim((string) ($data['title'] ?? ''));
        $slug = trim((string) ($data['slug'] ?? ''));
        if ($title === '' || $slug === '') {
            throw new InvalidArgumentException('Title and slug are required.');
        }

        $status = strtolower((string) ($data['status'] ?? 'draft'));
        if (!in_array($status, ['draft', 'published'], true)) {
            throw new InvalidArgumentException('Status must be draft or published.');
        }

        $content = (string) ($data['content'] ?? '');
        $content = (new HtmlSanitizer())->sanitize($content);

        $repo = $this->repository();
        $id = $repo->create([
            'title' => $title,
            'slug' => $slug,
            'content' => $content,
            'status' => $status,
        ]);

        $page = $repo->findById($id);
        if ($page === null) {
            throw new RuntimeException('Failed to load created page.');
        }

        return $page;
    }

    /** @mutation */
    public function update(int $id, array $data): void
    {
        if ($id <= 0) {
            throw new InvalidArgumentException('Page id must be positive.');
        }

        $title = trim((string) ($data['title'] ?? ''));
        $slug = trim((string) ($data['slug'] ?? ''));
        if ($title === '' || $slug === '') {
            throw new InvalidArgumentException('Title and slug are required.');
        }

        $status = strtolower((string) ($data['status'] ?? 'draft'));
        if (!in_array($status, ['draft', 'published'], true)) {
            throw new InvalidArgumentException('Status must be draft or published.');
        }

        $content = (string) ($data['content'] ?? '');
        $content = (new HtmlSanitizer())->sanitize($content);

        $this->repository()->update($id, [
            'title' => $title,
            'slug' => $slug,
            'content' => $content,
            'status' => $status,
        ]);
    }

    /** @mutation */
    public function updateStatus(int $id, string $status): void
    {
        if ($id <= 0) {
            throw new InvalidArgumentException('Page id must be positive.');
        }

        $status = strtolower(trim($status));
        if (!in_array($status, ['draft', 'published'], true)) {
            throw new InvalidArgumentException('Status must be draft or published.');
        }

        $this->repository()->updateStatus($id, $status);
    }

    /** @mutation */
    public function delete(int $id): void
    {
        if ($id <= 0) {
            throw new InvalidArgumentException('Page id must be positive.');
        }

        $this->repository()->delete($id);
    }

    /**
     * @return array<int, array{type: string, data: array<string, mixed>}>
     */
    public function findLatestBlocks(int $pageId): array
    {
        if ($pageId <= 0) {
            throw new InvalidArgumentException('Page id must be positive.');
        }

        $blocks = $this->revisionsRepository()->findLatestBlocksByPageId($pageId);
        return is_array($blocks) ? $blocks : [];
    }

    /** @return array<string, mixed>|null */
    public function findLatestRevision(int $pageId): ?array
    {
        if ($pageId <= 0) {
            throw new InvalidArgumentException('Page id must be positive.');
        }

        return $this->revisionsRepository()->findLatestByPageId($pageId);
    }

    /**
     * @param array<int, array<string, mixed>> $blocks
     * @mutation
     */
    public function createRevision(int $pageId, array $blocks, ?int $createdBy): int
    {
        if ($pageId <= 0) {
            throw new InvalidArgumentException('Page id must be positive.');
        }

        return $this->revisionsRepository()->createRevision($pageId, $blocks, $createdBy);
    }

    /** @mutation */
    public function deleteRevisionsByPageId(int $pageId): void
    {
        if ($pageId <= 0) {
            throw new InvalidArgumentException('Page id must be positive.');
        }

        $this->revisionsRepository()->deleteByPageId($pageId);
    }

    private function repository(): PagesRepository
    {
        if ($this->repository !== null) {
            return $this->repository;
        }

        if (!$this->db->healthCheck()) {
            throw new RuntimeException('Database unavailable.');
        }

        try {
            $this->repository = new PagesRepository($this->db);
        } catch (Throwable $e) {
            throw new RuntimeException('Database unavailable.', 0, $e);
        }

        return $this->repository;
    }

    private function revisionsRepository(): PagesRevisionsRepository
    {
        if ($this->revisions !== null) {
            return $this->revisions;
        }

        if (!$this->db->healthCheck()) {
            throw new RuntimeException('Database unavailable.');
        }

        try {
            $this->revisions = new PagesRevisionsRepository($this->db);
        } catch (Throwable $e) {
            throw new RuntimeException('Database unavailable.', 0, $e);
        }

        return $this->revisions;
    }

    private function normalizeStatus(?string $status): string
    {
        $status = strtolower((string) ($status ?? ''));
        if (!in_array($status, ['published', 'draft', 'all'], true)) {
            return 'all';
        }

        return $status;
    }
}
