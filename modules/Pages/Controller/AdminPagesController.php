<?php
declare(strict_types=1);

namespace Laas\Modules\Pages\Controller;

use Laas\Database\DatabaseManager;
use Laas\Core\Validation\Validator;
use Laas\Core\Validation\ValidationResult;
use Laas\Http\Request;
use Laas\Http\Response;
use Laas\Modules\Pages\Repository\PagesRepository;
use Laas\Database\Repositories\RbacRepository;
use Laas\Support\AuditLogger;
use Laas\View\View;
use Throwable;

final class AdminPagesController
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

    public function index(Request $request): Response
    {
        if (!$this->canEdit()) {
            return $this->forbidden();
        }

        $repo = $this->getRepository();
        if ($repo === null) {
            return $this->errorResponse($request, 'db_unavailable', 503);
        }

        $query = trim((string) ($request->query('q') ?? ''));
        $status = (string) ($request->query('status') ?? 'all');
        if (!in_array($status, ['all', 'draft', 'published'], true)) {
            $status = 'all';
        }

        $rows = [];
        $canEdit = true;
        foreach ($repo->listForAdmin(100, 0, $query, $status) as $page) {
            $rows[] = $this->buildPageRow($page, $canEdit);
        }

        $viewData = [
            'pages' => $rows,
            'q' => $query,
            'status_selected_all' => $status === 'all' ? 'selected' : '',
            'status_selected_draft' => $status === 'draft' ? 'selected' : '',
            'status_selected_published' => $status === 'published' ? 'selected' : '',
        ];

        if ($request->isHtmx()) {
            return $this->view->render('partials/pages_table.html', $viewData, 200, [], [
                'theme' => 'admin',
                'render_partial' => true,
            ]);
        }

        return $this->view->render('pages/pages.html', [
            ...$viewData,
        ], 200, [], [
            'theme' => 'admin',
        ]);
    }

    public function createForm(Request $request): Response
    {
        if (!$this->canEdit()) {
            return $this->forbidden();
        }

        return $this->view->render('pages/page_form.html', [
            'mode' => 'create',
            'is_edit' => false,
            'page' => $this->emptyPage(),
            'status_selected_draft' => 'selected',
            'status_selected_published' => '',
        ], 200, [], [
            'theme' => 'admin',
        ]);
    }

    public function editForm(Request $request, array $params = []): Response
    {
        if (!$this->canEdit()) {
            return $this->forbidden();
        }

        $id = isset($params['id']) ? (int) $params['id'] : 0;
        if ($id <= 0) {
            return $this->notFound();
        }

        $repo = $this->getRepository();
        if ($repo === null) {
            return $this->errorResponse($request, 'db_unavailable', 503);
        }

        $page = $repo->findById($id);
        if ($page === null) {
            return $this->notFound();
        }

        $status = (string) ($page['status'] ?? 'draft');
        return $this->view->render('pages/page_form.html', [
            'mode' => 'edit',
            'is_edit' => true,
            'page' => $page,
            'status_selected_draft' => $status === 'draft' ? 'selected' : '',
            'status_selected_published' => $status === 'published' ? 'selected' : '',
        ], 200, [], [
            'theme' => 'admin',
        ]);
    }

    public function save(Request $request): Response
    {
        if (!$this->canEdit()) {
            return $this->forbidden();
        }

        $repo = $this->getRepository();
        if ($repo === null) {
            return $this->errorResponse($request, 'db_unavailable', 503);
        }

        $id = $this->readId($request);
        $title = trim((string) ($request->post('title') ?? ''));
        $slug = trim((string) ($request->post('slug') ?? ''));
        $content = (string) ($request->post('content') ?? '');
        $status = (string) ($request->post('status') ?? 'draft');

        $data = [
            'title' => $title,
            'slug' => $slug,
            'content' => $content,
            'status' => $status,
        ];

        $uniqueRule = 'unique:pages,slug';
        if ($id !== null) {
            $uniqueRule .= ',' . $id;
        }

        $validator = new Validator();
        $reservedRule = 'reserved_slug:' . implode(',', self::RESERVED);
        $result = $validator->validate($data, [
            'title' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'slug', 'max:255', $uniqueRule, $reservedRule],
            'status' => ['required', 'in:draft,published'],
            'content' => ['string'],
        ], [
            'db' => $this->db,
            'label_prefix' => 'pages',
            'translator' => $this->view->getTranslator(),
        ]);

        if (!$result->isValid()) {
            return $this->formErrorResponse($request, $result, [
                'id' => $id ?? 0,
                'title' => $title,
                'slug' => $slug,
                'content' => $content,
                'status' => $status,
            ]);
        }

        $audit = new AuditLogger($this->db);

        if ($id === null) {
            $newId = $repo->create([
                'title' => $title,
                'slug' => $slug,
                'content' => $content,
                'status' => $status,
            ]);
            $audit->log(
                'pages.create',
                'page',
                $newId,
                [
                    'title' => $title,
                    'slug' => $slug,
                    'status' => $status,
                ],
                $this->currentUserId(),
                $request->ip()
            );
        } else {
            $repo->update($id, [
                'title' => $title,
                'slug' => $slug,
                'content' => $content,
                'status' => $status,
            ]);
            $audit->log(
                'pages.update',
                'page',
                $id,
                [
                    'title' => $title,
                    'slug' => $slug,
                    'status' => $status,
                ],
                $this->currentUserId(),
                $request->ip()
            );
        }

        if ($request->isHtmx()) {
            return $this->view->render('partials/page_form_messages.html', [
                'saved_message' => true,
            ], 200, [], [
                'theme' => 'admin',
            ]);
        }

        return new Response('', 302, [
            'Location' => '/admin/pages',
        ]);
    }

    public function delete(Request $request): Response
    {
        if (!$this->canEdit()) {
            return $this->forbidden();
        }

        $id = $this->readId($request);
        if ($id === null) {
            return $this->notFound();
        }

        $repo = $this->getRepository();
        if ($repo === null) {
            return $this->errorResponse($request, 'db_unavailable', 503);
        }

        $page = $repo->findById($id);
        if ($page === null) {
            return $this->notFound();
        }

        $repo->delete($id);
        (new AuditLogger($this->db))->log(
            'pages.delete',
            'page',
            $id,
            [
                'title' => (string) ($page['title'] ?? ''),
                'slug' => (string) ($page['slug'] ?? ''),
            ],
            $this->currentUserId(),
            $request->ip()
        );

        if ($request->isHtmx()) {
            return new Response('', 200);
        }

        return new Response('', 302, [
            'Location' => '/admin/pages',
        ]);
    }

    public function toggleStatus(Request $request): Response
    {
        if (!$this->canEdit()) {
            return $this->errorResponse($request, 'forbidden', 403);
        }

        $id = $this->readId($request);
        if ($id === null) {
            return $this->errorResponse($request, 'invalid_request', 400);
        }

        $repo = $this->getRepository();
        if ($repo === null) {
            return $this->errorResponse($request, 'db_unavailable', 503);
        }

        $page = $repo->findById($id);
        if ($page === null) {
            return $this->errorResponse($request, 'not_found', 404);
        }

        $status = (string) ($page['status'] ?? 'draft');
        $nextStatus = $status === 'published' ? 'draft' : 'published';
        $repo->updateStatus($id, $nextStatus);

        $page['status'] = $nextStatus;
        $row = $this->buildPageRow($page, true);
        $row['flash'] = true;

        if ($request->isHtmx()) {
            return $this->view->render('partials/page_row.html', [
                'page' => $row,
            ], 200, [], [
                'theme' => 'admin',
            ]);
        }

        return new Response('', 302, [
            'Location' => '/admin/pages',
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

    private function canEdit(): bool
    {
        if ($this->db === null || !$this->db->healthCheck()) {
            return false;
        }

        $userId = $this->currentUserId();
        if ($userId === null) {
            return false;
        }

        try {
            $rbac = new RbacRepository($this->db->pdo());
            return $rbac->userHasPermission($userId, 'pages.edit');
        } catch (Throwable) {
            return false;
        }
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

    private function emptyPage(): array
    {
        return [
            'id' => 0,
            'title' => '',
            'slug' => '',
            'content' => '',
            'status' => 'draft',
        ];
    }

    private function readId(Request $request): ?int
    {
        $raw = $request->post('id');
        if ($raw === null || $raw === '') {
            return null;
        }
        if (!ctype_digit($raw)) {
            return null;
        }
        $id = (int) $raw;
        return $id > 0 ? $id : null;
    }

    private function formErrorResponse(Request $request, ValidationResult|array $errors, array $page): Response
    {
        $messages = $this->resolveErrorMessages($errors);

        if ($request->isHtmx()) {
            return $this->view->render('partials/page_form_messages.html', [
                'errors' => $messages,
            ], 422, [], [
                'theme' => 'admin',
            ]);
        }

        $isEdit = !empty($page['id']);
        $status = (string) ($page['status'] ?? 'draft');
        return $this->view->render('pages/page_form.html', [
            'mode' => $isEdit ? 'edit' : 'create',
            'is_edit' => $isEdit,
            'page' => $page,
            'status_selected_draft' => $status === 'draft' ? 'selected' : '',
            'status_selected_published' => $status === 'published' ? 'selected' : '',
            'errors' => $messages,
        ], 422, [], [
            'theme' => 'admin',
        ]);
    }

    private function forbidden(): Response
    {
        return $this->view->render('pages/403.html', [], 403, [], [
            'theme' => 'admin',
        ]);
    }

    private function notFound(): Response
    {
        return new Response('Not Found', 404, [
            'Content-Type' => 'text/plain; charset=utf-8',
        ]);
    }

    private function errorResponse(Request $request, string $code, int $status): Response
    {
        if ($request->isHtmx() || $request->wantsJson()) {
            return Response::json(['error' => $code], $status);
        }

        return new Response('Error', $status, [
            'Content-Type' => 'text/plain; charset=utf-8',
        ]);
    }

    /** @return array<int, string> */
    private function resolveErrorMessages(ValidationResult|array $errors): array
    {
        $messages = [];

        if ($errors instanceof ValidationResult) {
            foreach ($errors->errors() as $fieldErrors) {
                foreach ($fieldErrors as $error) {
                    $messages[] = $this->view->translate((string) $error['key'], $error['params'] ?? []);
                }
            }

            return $messages;
        }

        foreach ($errors as $error) {
            $messages[] = $this->view->translate((string) ($error['key'] ?? ''), $error['params'] ?? []);
        }

        return $messages;
    }

    private function buildPageRow(array $page, bool $canEdit): array
    {
        $status = (string) ($page['status'] ?? 'draft');
        $isPublished = $status === 'published';
        $updatedAt = (string) ($page['updated_at'] ?? '');

        return [
            'id' => (int) ($page['id'] ?? 0),
            'title' => (string) ($page['title'] ?? ''),
            'slug' => (string) ($page['slug'] ?? ''),
            'status' => $status,
            'is_published' => $isPublished,
            'status_badge_class' => $isPublished ? 'bg-success' : 'bg-secondary',
            'updated_at' => $updatedAt,
            'updated_at_display' => $updatedAt !== '' ? $updatedAt : '-',
            'can_edit' => $canEdit,
        ];
    }
}
