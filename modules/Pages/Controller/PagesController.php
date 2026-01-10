<?php
declare(strict_types=1);

namespace Laas\Modules\Pages\Controller;

use Laas\Database\DatabaseManager;
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
            return $this->notFound();
        }

        $repo = $this->getRepository();
        if ($repo === null) {
            return $this->notFound();
        }

        $page = $repo->findPublishedBySlug($slug);
        if ($page === null) {
            return $this->notFound();
        }

        $vm = PagePublicViewModel::fromArray($page);
        return $this->view->render('pages/page.html', $vm);
    }

    public function search(Request $request): Response
    {
        $repo = $this->getRepository();
        if ($repo === null) {
            return new Response('Service Unavailable', 503, [
                'Content-Type' => 'text/plain; charset=utf-8',
            ]);
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

    private function notFound(): Response
    {
        return new Response('Not Found', 404, [
            'Content-Type' => 'text/plain; charset=utf-8',
        ]);
    }
}
