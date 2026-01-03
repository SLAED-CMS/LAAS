<?php
declare(strict_types=1);

namespace Laas\Modules\Admin\Controller;

use Laas\Database\DatabaseManager;
use Laas\Database\Repositories\RbacRepository;
use Laas\Database\Repositories\UsersRepository;
use Laas\Http\Request;
use Laas\Http\Response;
use Laas\Modules\Media\Repository\MediaRepository;
use Laas\Modules\Pages\Repository\PagesRepository;
use Laas\Support\Search\Highlighter;
use Laas\Support\Search\SearchNormalizer;
use Laas\Support\Search\SearchQuery;
use Laas\View\View;
use Throwable;

final class AdminSearchController
{
    public function __construct(
        private View $view,
        private ?DatabaseManager $db = null
    ) {
    }

    public function index(Request $request): Response
    {
        $query = SearchNormalizer::normalize((string) ($request->query('q') ?? ''));
        if (SearchNormalizer::isTooShort($query)) {
            $message = $this->view->translate('search.too_short');
            if ($request->isHtmx()) {
                $response = $this->view->render('partials/messages.html', [
                    'errors' => [$message],
                ], 422, [], [
                    'theme' => 'admin',
                    'render_partial' => true,
                ]);
                return $response->withHeader('HX-Retarget', '#page-messages');
            }

            return $this->view->render('search/index.html', [
                'errors' => [$message],
                'q' => $query,
                'admin_search_results' => true,
                'search' => $this->emptySearch($query),
            ], 422, [], [
                'theme' => 'admin',
            ]);
        }

        $search = $this->buildSearch($query);
        $data = [
            'q' => $query,
            'errors' => [],
            'admin_search_results' => true,
            'search' => $search,
        ];

        if ($request->isHtmx()) {
            return $this->view->render('search/results_partial.html', $data, 200, [], [
                'theme' => 'admin',
                'render_partial' => true,
            ]);
        }

        return $this->view->render('search/index.html', $data, 200, [], [
            'theme' => 'admin',
        ]);
    }

    private function buildSearch(string $query): array
    {
        $result = $this->emptySearch($query);
        if ($query === '') {
            return $result;
        }

        $db = $this->db;
        if ($db === null || !$db->healthCheck()) {
            return $result;
        }

        $userId = $this->currentUserId();
        $rbac = $this->getRbacRepository();
        if ($userId === null || $rbac === null) {
            return $result;
        }

        $canPages = $this->canAny($rbac, $userId, ['pages.edit', 'pages.view']);
        $canMedia = $this->canAny($rbac, $userId, ['media.view']);
        $canUsers = $this->canAny($rbac, $userId, ['users.manage', 'users.view']);

        $limit = 10;
        $searchQuery = new SearchQuery($query, $limit, 1, 'admin');

        if ($canPages) {
            $repo = $this->getPagesRepository();
            if ($repo !== null) {
                $rows = $repo->search($searchQuery->q, $searchQuery->limit, $searchQuery->offset, null);
                foreach ($rows as $row) {
                    $result['pages'][] = $this->mapPageResult($row, $searchQuery->q);
                }
            }
        }

        if ($canMedia) {
            $repo = $this->getMediaRepository();
            if ($repo !== null) {
                $rows = $repo->search($searchQuery->q, $searchQuery->limit, $searchQuery->offset);
                foreach ($rows as $row) {
                    $result['media'][] = $this->mapMediaResult($row, $searchQuery->q);
                }
            }
        }

        if ($canUsers) {
            $repo = $this->getUsersRepository();
            if ($repo !== null) {
                $rows = $repo->search($searchQuery->q, $searchQuery->limit, $searchQuery->offset);
                foreach ($rows as $row) {
                    $result['users'][] = $this->mapUserResult($row, $searchQuery->q);
                }
            }
        }

        $result['has_results'] = $result['pages'] !== [] || $result['media'] !== [] || $result['users'] !== [];
        $result['scopes'] = [
            ['key' => 'pages', 'label' => 'admin.search.scope.pages', 'items' => $result['pages']],
            ['key' => 'media', 'label' => 'admin.search.scope.media', 'items' => $result['media']],
            ['key' => 'users', 'label' => 'admin.search.scope.users', 'items' => $result['users']],
        ];

        return $result;
    }

    private function emptySearch(string $query): array
    {
        return [
            'query' => $query,
            'pages' => [],
            'media' => [],
            'users' => [],
            'scopes' => [],
            'has_results' => false,
        ];
    }

    private function mapPageResult(array $row, string $query): array
    {
        $id = (int) ($row['id'] ?? 0);
        $title = (string) ($row['title'] ?? '');
        $slug = (string) ($row['slug'] ?? '');
        $status = (string) ($row['status'] ?? '');

        return [
            'id' => $id,
            'title' => $title,
            'slug' => $slug,
            'status' => $status,
            'url' => $id > 0 ? '/admin/pages/' . $id . '/edit' : '/admin/pages',
            'title_segments' => Highlighter::segments($title, $query),
            'slug_segments' => Highlighter::segments($slug, $query),
        ];
    }

    private function mapMediaResult(array $row, string $query): array
    {
        $id = (int) ($row['id'] ?? 0);
        $name = (string) ($row['original_name'] ?? '');
        $mime = (string) ($row['mime_type'] ?? '');

        return [
            'id' => $id,
            'name' => $name,
            'mime' => $mime,
            'url' => $id > 0 ? '/media/' . $id . '/file' : '#',
            'name_segments' => Highlighter::segments($name, $query),
            'mime_segments' => Highlighter::segments($mime, $query),
        ];
    }

    private function mapUserResult(array $row, string $query): array
    {
        $id = (int) ($row['id'] ?? 0);
        $username = (string) ($row['username'] ?? '');
        $email = (string) ($row['email'] ?? '');

        return [
            'id' => $id,
            'username' => $username,
            'email' => $email,
            'url' => $id > 0 ? '/admin/users#user-' . $id : '/admin/users',
            'username_segments' => Highlighter::segments($username, $query),
            'email_segments' => Highlighter::segments($email, $query),
        ];
    }

    private function currentUserId(): ?int
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return null;
        }

        $raw = $_SESSION['user_id'] ?? null;
        if (is_int($raw)) {
            return $raw;
        }

        if (is_string($raw) && ctype_digit($raw)) {
            return (int) $raw;
        }

        return null;
    }

    private function getPagesRepository(): ?PagesRepository
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

    private function getMediaRepository(): ?MediaRepository
    {
        if ($this->db === null || !$this->db->healthCheck()) {
            return null;
        }

        try {
            return new MediaRepository($this->db);
        } catch (Throwable) {
            return null;
        }
    }

    private function getUsersRepository(): ?UsersRepository
    {
        if ($this->db === null || !$this->db->healthCheck()) {
            return null;
        }

        try {
            return new UsersRepository($this->db->pdo());
        } catch (Throwable) {
            return null;
        }
    }

    private function getRbacRepository(): ?RbacRepository
    {
        if ($this->db === null || !$this->db->healthCheck()) {
            return null;
        }

        try {
            return new RbacRepository($this->db->pdo());
        } catch (Throwable) {
            return null;
        }
    }

    private function canAny(RbacRepository $rbac, int $userId, array $permissions): bool
    {
        foreach ($permissions as $permission) {
            try {
                if ($rbac->userHasPermission($userId, $permission)) {
                    return true;
                }
            } catch (Throwable) {
                return false;
            }
        }

        return false;
    }
}
