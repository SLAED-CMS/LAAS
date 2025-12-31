<?php
declare(strict_types=1);

namespace Laas\Modules\Pages\Controller;

use Laas\Database\DatabaseManager;
use Laas\Http\Request;
use Laas\Http\Response;
use Laas\Modules\Pages\Repository\PagesRepository;
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

        return $this->view->render('pages/page.html', [
            'page' => $page,
            'title' => (string) ($page['title'] ?? ''),
            'content' => (string) ($page['content'] ?? ''),
        ]);
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
