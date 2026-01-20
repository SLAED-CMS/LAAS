<?php
declare(strict_types=1);

namespace Laas\Domain\Pages;

use InvalidArgumentException;
use Laas\Database\DatabaseManager;
use Laas\Modules\Pages\Repository\PagesRepository;
use Laas\Security\HtmlSanitizer;
use RuntimeException;
use Throwable;

class PagesService implements PagesServiceInterface
{
    private ?PagesRepository $repository = null;

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

    private function normalizeStatus(?string $status): string
    {
        $status = strtolower((string) ($status ?? ''));
        if (!in_array($status, ['published', 'draft', 'all'], true)) {
            return 'all';
        }

        return $status;
    }
}
