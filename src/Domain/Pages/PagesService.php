<?php

declare(strict_types=1);

namespace Laas\Domain\Pages;

use InvalidArgumentException;
use Laas\Content\ContentNormalizer;
use Laas\Content\MarkdownRenderer;
use Laas\Database\DatabaseManager;
use Laas\Domain\Pages\Dto\PageSummary;
use Laas\Domain\Pages\Dto\PageView;
use Laas\Modules\Pages\Repository\PagesRepository;
use Laas\Modules\Pages\Repository\PagesRevisionsRepository;
use Laas\Security\ContentProfiles;
use Laas\Security\HtmlSanitizer;
use RuntimeException;
use Throwable;

class PagesService implements PagesServiceInterface, PagesReadServiceInterface, PagesWriteServiceInterface
{
    private ?PagesRepository $repository = null;
    private ?PagesRevisionsRepository $revisions = null;

    /**
     * @param array<string, mixed> $config
     */
    public function __construct(
        private DatabaseManager $db,
        private array $config = [],
        private ?ContentNormalizer $contentNormalizer = null
    ) {
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

    /** @return PageSummary[] */
    public function listPublishedSummaries(): array
    {
        $rows = $this->repository()->listPublishedAll();
        $out = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $out[] = PageSummary::fromArray($row);
        }
        return $out;
    }

    /**
     * @param array<int, string> $fields
     * @param array<int, string> $include
     */
    public function getPublishedView(string $slug, string $locale, array $fields = [], array $include = []): ?PageView
    {
        $slug = trim($slug);
        if ($slug === '') {
            return null;
        }

        $row = $this->repository()->findPublishedBySlug($slug);
        if ($row === null) {
            return null;
        }

        $view = PageView::fromArray($row, $locale);

        $includeBlocks = in_array('blocks', $include, true) || in_array('blocks', $fields, true);
        $includeMedia = in_array('media', $include, true);
        if ($includeBlocks || $includeMedia) {
            $pageId = (int) ($row['id'] ?? 0);
            $blocks = $pageId > 0 ? $this->findLatestBlocks($pageId) : [];
            $view = $view->withBlocks($blocks);
        }

        return $view;
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
        $contentFormat = $data['content_format'] ?? null;
        $content = $this->normalizeContent($content, $contentFormat);

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
        $contentFormat = $data['content_format'] ?? null;
        $content = $this->normalizeContent($content, $contentFormat);

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

    public function findLatestRevisionId(int $pageId): int
    {
        if ($pageId <= 0) {
            throw new InvalidArgumentException('Page id must be positive.');
        }

        return $this->revisionsRepository()->findLatestRevisionIdByPageId($pageId);
    }

    /** @return array<int, int> */
    public function findLatestRevisionIds(array $pageIds): array
    {
        $pageIds = array_values(array_unique(array_filter(array_map('intval', $pageIds), static fn (int $id): bool => $id > 0)));
        if ($pageIds === []) {
            return [];
        }

        return $this->revisionsRepository()->findLatestRevisionIdsByPageIds($pageIds);
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

        $blocks = $this->normalizeBlocks($blocks);

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

    /**
     * @param array<int, array<string, mixed>> $blocks
     * @return array<int, array<string, mixed>>
     */
    private function normalizeBlocks(array $blocks): array
    {
        if (!$this->blocksNormalizeEnabled()) {
            return $blocks;
        }

        $normalizer = $this->contentNormalizer();
        foreach ($blocks as $index => $block) {
            $type = (string) ($block['type'] ?? '');
            if ($type !== 'rich_text') {
                continue;
            }

            $data = $block['data'] ?? null;
            if (!is_array($data)) {
                continue;
            }

            $html = (string) ($data['html'] ?? '');
            $data['html'] = $normalizer->normalize($html, 'html', ContentProfiles::EDITOR_SAFE_RICH);
            $block['data'] = $data;
            $blocks[$index] = $block;
        }

        return $blocks;
    }

    private function blocksNormalizeEnabled(): bool
    {
        $appConfig = $this->config['app'] ?? [];
        return (bool) ($appConfig['blocks_normalize_enabled'] ?? false);
    }

    private function normalizeContent(string $content, mixed $format): string
    {
        if (!$this->pagesNormalizeEnabled()) {
            return (new HtmlSanitizer())->sanitize($content, ContentProfiles::LEGACY);
        }

        $normalizer = $this->contentNormalizer();
        $normalizedFormat = $this->normalizeContentFormat($format);

        return $normalizer->normalize($content, $normalizedFormat, ContentProfiles::EDITOR_SAFE_RICH);
    }

    private function pagesNormalizeEnabled(): bool
    {
        $appConfig = $this->config['app'] ?? [];
        return (bool) ($appConfig['pages_normalize_enabled'] ?? false);
    }

    private function normalizeContentFormat(mixed $format): string
    {
        $format = strtolower(trim((string) $format));
        return in_array($format, ['markdown', 'html'], true) ? $format : 'html';
    }

    private function contentNormalizer(): ContentNormalizer
    {
        if ($this->contentNormalizer === null) {
            $this->contentNormalizer = new ContentNormalizer(
                new MarkdownRenderer(),
                new HtmlSanitizer()
            );
        }

        return $this->contentNormalizer;
    }
}
