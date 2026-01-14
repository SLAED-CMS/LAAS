<?php
declare(strict_types=1);

namespace Laas\Modules\Pages\Controller;

use Laas\Database\DatabaseManager;
use Laas\Http\Contract\ContractResponse;
use Laas\Http\ErrorResponse;
use Laas\Http\Request;
use Laas\Http\Response;
use Laas\Modules\Pages\Repository\PagesRepository;
use Laas\Modules\Pages\ViewModel\PagePublicViewModel;
use Laas\Support\Search\Highlighter;
use Laas\Support\Search\SearchNormalizer;
use Laas\Support\Search\SearchQuery;
use Laas\View\View;
use Throwable;

final class PagesController
{
    private const RESERVED = [
        'admin',
        'api',
        'login',
        'logout',
        'csrf',
        'echo',
        'assets',
        'themes',
    ];

    public function __construct(
        private View $view,
        private ?DatabaseManager $db = null
    ) {
    }

    public function show(Request $request, array $params = []): Response
    {
        $slug = $params['slug'] ?? '';
        if (!is_string($slug) || $slug === '' || in_array($slug, self::RESERVED, true)) {
            return $this->notFound($request);
        }

        $repo = $this->getRepository();
        if ($repo === null) {
            return $this->notFound($request);
        }

        $page = $repo->findPublishedBySlug($slug);
        if ($page === null) {
            return $this->notFound($request);
        }

        $vm = PagePublicViewModel::fromArray($page);
        if ($this->shouldJson($request)) {
            return ContractResponse::ok([
                'page' => $this->jsonPage($page),
            ], [
                'route' => 'pages.show',
            ]);
        }

        return $this->view->render('pages/page.html', $vm);
    }

    public function search(Request $request): Response
    {
        $repo = $this->getRepository();
        if ($repo === null) {
            return ErrorResponse::respondForRequest($request, 'service_unavailable', [], 503, [], 'pages.search');
        }

        $query = SearchNormalizer::normalize((string) ($request->query('q') ?? ''));
        $errors = [];
        $results = [];
        $status = 200;

        if (SearchNormalizer::isTooShort($query)) {
            $errors[] = $this->view->translate('search.too_short');
            $status = 422;
        } elseif ($query !== '') {
            $search = new SearchQuery($query, 20, 1, 'pages');
            $rows = $repo->search($search->q, $search->limit, $search->offset, 'published');
            foreach ($rows as $row) {
                $title = (string) ($row['title'] ?? '');
                $slug = (string) ($row['slug'] ?? '');
                $content = (string) ($row['content'] ?? '');

                $results[] = [
                    'title_segments' => Highlighter::segments($title, $search->q),
                    'snippet_segments' => Highlighter::snippet($content, $search->q, 160),
                    'url' => '/' . $slug,
                ];
            }
        }

        return $this->view->render('pages/search.html', [
            'q' => $query,
            'results' => $results,
            'has_query' => $query !== '',
            'count' => count($results),
            'errors' => $errors,
        ], $status);
    }

    private function getRepository(): ?PagesRepository
    {
        if ($this->db === null || !$this->db->healthCheck()) {
            return null;
        }

        try {
            return new PagesRepository($this->db);
        } catch (Throwable) {
            return null;
        }
    }

    private function notFound(Request $request): Response
    {
        if ($this->shouldJson($request)) {
            return ContractResponse::error('not_found', [
                'route' => 'pages.show',
            ], 404);
        }

        return ErrorResponse::respondForRequest($request, 'not_found', [], 404, [], 'pages.show');
    }

    private function shouldJson(Request $request): bool
    {
        return $request->wantsJson();
    }

    /** @param array<string, mixed> $page */
    private function jsonPage(array $page): array
    {
        $id = $page['id'] ?? null;
        if (is_string($id) && ctype_digit($id)) {
            $id = (int) $id;
        } elseif (!is_int($id)) {
            $id = null;
        }

        return [
            'id' => $id,
            'slug' => (string) ($page['slug'] ?? ''),
            'title' => (string) ($page['title'] ?? ''),
            'content' => (string) ($page['content'] ?? ''),
            'updated_at' => (string) ($page['updated_at'] ?? ''),
        ];
    }
}
