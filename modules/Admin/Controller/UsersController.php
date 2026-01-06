<?php
declare(strict_types=1);

namespace Laas\Modules\Admin\Controller;

use Laas\Database\DatabaseManager;
use Laas\Database\Repositories\RbacRepository;
use Laas\Database\Repositories\UsersRepository;
use Laas\Http\Request;
use Laas\Http\Response;
use Laas\Support\AuditLogger;
use Laas\Support\Search\Highlighter;
use Laas\Support\Search\SearchNormalizer;
use Laas\Support\Search\SearchQuery;
use Laas\View\View;
use Throwable;

final class UsersController
{
    private const MIN_PASSWORD_LENGTH = 8;

    public function __construct(
        private View $view,
        private ?DatabaseManager $db = null
    ) {
    }

    public function index(Request $request): Response
    {
        if (!$this->canManage($request)) {
            return $this->forbidden($request);
        }

        $repo = $this->getUsersRepository();
        $rbac = $this->getRbacRepository();
        if ($repo === null || $rbac === null) {
            return $this->errorResponse($request, 'db_unavailable', 503);
        }

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

            return $this->view->render('pages/users.html', [
                'users' => [],
                'q' => $query,
                'errors' => [$message],
            ], 422, [], [
                'theme' => 'admin',
            ]);
        }

        $currentUserId = $this->currentUserId($request);
        if ($query !== '') {
            $search = new SearchQuery($query, 50, 1, 'users');
            $users = $repo->search($search->q, $search->limit, $search->offset);
        } else {
            $users = $repo->list(100, 0);
        }
        $rows = [];
        $userIds = [];
        foreach ($users as $user) {
            $userId = (int) ($user['id'] ?? 0);
            if ($userId > 0) {
                $userIds[] = $userId;
            }
        }

        $rolesMap = $rbac->getRolesForUsers($userIds);
        foreach ($users as $user) {
            $userId = (int) ($user['id'] ?? 0);
            if ($userId <= 0) {
                continue;
            }

            $roles = $rolesMap[$userId] ?? [];
            $isAdmin = in_array('admin', $roles, true);
            $rows[] = $this->mapUserRow($user, $isAdmin, $currentUserId, $query);
        }

        $viewData = [
            'users' => $rows,
            'q' => $query,
            'errors' => [],
        ];

        if ($request->isHtmx()) {
            return $this->view->render('partials/users_table.html', $viewData, 200, [], [
                'theme' => 'admin',
                'render_partial' => true,
            ]);
        }

        return $this->view->render('pages/users.html', $viewData, 200, [], [
            'theme' => 'admin',
        ]);
    }

    public function toggleStatus(Request $request): Response
    {
        if (!$this->canManage($request)) {
            return $this->forbidden($request);
        }

        $userId = $this->readUserId($request);
        if ($userId === null) {
            return $this->errorResponse($request, 'invalid_request', 400);
        }

        $repo = $this->getUsersRepository();
        $rbac = $this->getRbacRepository();
        if ($repo === null || $rbac === null) {
            return $this->errorResponse($request, 'db_unavailable', 503);
        }

        $currentUserId = $this->currentUserId($request);
        if ($currentUserId !== null && $userId === $currentUserId) {
            return $this->errorResponse($request, 'self_protected', 400);
        }

        $user = $repo->findById($userId);
        if ($user === null) {
            return $this->errorResponse($request, 'not_found', 404);
        }

        $currentStatus = (int) ($user['status'] ?? 0);
        $nextStatus = $currentStatus === 1 ? 0 : 1;
        $repo->setStatus($userId, $nextStatus);

        $actorId = $currentUserId;
        (new AuditLogger($this->db, $request->session()))->log(
            'users.status.updated',
            'user',
            $userId,
            [
                'actor_user_id' => $actorId,
                'target_user_id' => $userId,
                'from_status' => $currentStatus,
                'to_status' => $nextStatus,
            ],
            $actorId,
            $request->ip()
        );

        $isAdmin = $rbac->userHasRole($userId, 'admin');
        $row = $this->mapUserRow($user, $isAdmin, $currentUserId);
        $row['status'] = $nextStatus;

        return $this->renderRow($request, $row);
    }

    public function toggleAdmin(Request $request): Response
    {
        if (!$this->canManage($request)) {
            return $this->forbidden($request);
        }

        $userId = $this->readUserId($request);
        if ($userId === null) {
            return $this->errorResponse($request, 'invalid_request', 400);
        }

        $repo = $this->getUsersRepository();
        $rbac = $this->getRbacRepository();
        if ($repo === null || $rbac === null) {
            return $this->errorResponse($request, 'db_unavailable', 503);
        }

        $currentUserId = $this->currentUserId($request);
        if ($currentUserId !== null && $userId === $currentUserId) {
            return $this->errorResponse($request, 'self_protected', 400);
        }

        $user = $repo->findById($userId);
        if ($user === null) {
            return $this->errorResponse($request, 'not_found', 404);
        }

        $isAdmin = $rbac->userHasRole($userId, 'admin');
        if ($isAdmin) {
            $rbac->revokeRoleFromUser($userId, 'admin');
        } else {
            $rbac->grantRoleToUser($userId, 'admin');
        }

        $actorId = $currentUserId;
        $audit = new AuditLogger($this->db, $request->session());
        $audit->log('rbac.user.roles.updated', 'user', $userId, [
            'actor_user_id' => $actorId,
            'target_user_id' => $userId,
            'added_roles' => $isAdmin ? [] : ['admin'],
            'removed_roles' => $isAdmin ? ['admin'] : [],
        ], $actorId, $request->ip());

        $row = $this->mapUserRow($user, !$isAdmin, $currentUserId);
        return $this->renderRow($request, $row);
    }

    public function changePassword(Request $request): Response
    {
        if (!$this->canManage($request)) {
            return $this->forbidden($request);
        }

        $userId = $this->readUserId($request);
        if ($userId === null) {
            return $this->errorResponse($request, 'invalid_request', 400);
        }

        $password = (string) ($request->post('password') ?? '');
        if (trim($password) === '') {
            return $this->passwordError($request, 'admin.users.password_required');
        }
        if ($this->passwordLength($password) < self::MIN_PASSWORD_LENGTH) {
            return $this->passwordError($request, 'admin.users.password_too_short', [
                'min' => self::MIN_PASSWORD_LENGTH,
            ]);
        }
        if ($this->passwordWeak($password)) {
            return $this->passwordError($request, 'admin.users.password_weak');
        }

        $repo = $this->getUsersRepository();
        if ($repo === null) {
            return $this->errorResponse($request, 'db_unavailable', 503);
        }

        $user = $repo->findById($userId);
        if ($user === null) {
            return $this->errorResponse($request, 'not_found', 404);
        }

        $hash = password_hash($password, PASSWORD_DEFAULT);
        if ($hash === false) {
            return $this->errorResponse($request, 'invalid_request', 400);
        }

        $repo->setPasswordHash($userId, $hash);

        $actorId = $this->currentUserId($request);
        (new AuditLogger($this->db, $request->session()))->log(
            'users.password.changed',
            'user',
            $userId,
            [
                'actor_user_id' => $actorId,
                'target_user_id' => $userId,
            ],
            $actorId,
            $request->ip()
        );

        if ($request->isHtmx()) {
            return $this->renderMessages($request, [
                'success' => $this->view->translate('admin.users.password_changed'),
            ]);
        }

        if ($request->wantsJson()) {
            return Response::json(['ok' => true], 200);
        }

        return new Response('', 302, [
            'Location' => '/admin/users',
        ]);
    }

    public function delete(Request $request): Response
    {
        if (!$this->canManage($request)) {
            return $this->forbidden($request);
        }

        $userId = $this->readUserId($request);
        if ($userId === null) {
            return $this->errorResponse($request, 'invalid_request', 400);
        }

        $repo = $this->getUsersRepository();
        if ($repo === null || $this->db === null || !$this->db->healthCheck()) {
            return $this->errorResponse($request, 'db_unavailable', 503);
        }

        $currentUserId = $this->currentUserId($request);
        if ($currentUserId !== null && $userId === $currentUserId) {
            return $this->errorResponse($request, 'self_protected', 400);
        }

        $user = $repo->findById($userId);
        if ($user === null) {
            return $this->errorResponse($request, 'not_found', 404);
        }

        $stmt = $this->db->pdo()->prepare('DELETE FROM role_user WHERE user_id = :user_id');
        $stmt->execute(['user_id' => $userId]);
        $repo->delete($userId);

        $actorId = $currentUserId;
        (new AuditLogger($this->db, $request->session()))->log(
            'users.deleted',
            'user',
            $userId,
            [
                'actor_user_id' => $actorId,
                'target_user_id' => $userId,
                'username' => (string) ($user['username'] ?? ''),
            ],
            $actorId,
            $request->ip()
        );

        if ($request->isHtmx()) {
            return $this->renderMessages($request, [
                'success' => $this->view->translate('admin.users.deleted'),
            ], 200, [
                'HX-Trigger' => 'users:refresh',
            ]);
        }

        if ($request->wantsJson()) {
            return Response::json(['ok' => true], 200);
        }

        return new Response('', 302, [
            'Location' => '/admin/users',
        ]);
    }

    private function renderRow(Request $request, array $row): Response
    {
        if ($request->isHtmx()) {
            return $this->view->render('partials/user_row.html', [
                'user' => $row,
            ], 200, [], [
                'theme' => 'admin',
            ]);
        }

        return new Response('', 302, [
            'Location' => '/admin/users',
        ]);
    }

    private function mapUserRow(array $user, bool $isAdmin, ?int $currentUserId, ?string $query = null): array
    {
        $id = (int) ($user['id'] ?? 0);
        $status = (int) ($user['status'] ?? 0);
        $protected = $currentUserId !== null && $id === $currentUserId;
        $lastLogin = (string) ($user['last_login_at'] ?? '');
        $username = (string) ($user['username'] ?? '');
        $email = (string) ($user['email'] ?? '');

        return [
            'id' => $id,
            'username' => $username,
            'username_segments' => Highlighter::segments($username, $query ?? ''),
            'email' => $email,
            'email_segments' => Highlighter::segments($email, $query ?? ''),
            'status' => $status,
            'status_badge_class' => $status === 1 ? 'bg-success' : 'bg-secondary',
            'is_admin' => $isAdmin,
            'protected' => $protected,
            'last_login_at' => $lastLogin !== '' ? $lastLogin : '-',
        ];
    }

    private function readUserId(Request $request): ?int
    {
        $raw = $request->post('user_id');
        if ($raw === null || $raw === '') {
            return null;
        }

        if (!ctype_digit($raw)) {
            return null;
        }

        $id = (int) $raw;
        return $id > 0 ? $id : null;
    }

    private function currentUserId(Request $request): ?int
    {
        $session = $request->session();
        if (!$session->isStarted()) {
            return null;
        }

        $raw = $session->get('user_id');
        if (is_int($raw)) {
            return $raw;
        }
        if (is_string($raw) && ctype_digit($raw)) {
            return (int) $raw;
        }
        return null;
    }

    private function canManage(Request $request): bool
    {
        return $this->hasPermission($request, 'users.manage');
    }

    private function hasPermission(Request $request, string $permission): bool
    {
        if ($this->db === null || !$this->db->healthCheck()) {
            return false;
        }

        $userId = $this->currentUserId($request);
        if ($userId === null) {
            return false;
        }

        try {
            $rbac = new RbacRepository($this->db->pdo());
            return $rbac->userHasPermission($userId, $permission);
        } catch (Throwable) {
            return false;
        }
    }

    private function forbidden(Request $request): Response
    {
        if ($request->isHtmx() || $request->wantsJson()) {
            return Response::json(['error' => 'forbidden'], 403);
        }

        return $this->view->render('pages/403.html', [], 403, [], [
            'theme' => 'admin',
        ]);
    }

    private function renderMessages(Request $request, array $data, int $status = 200, array $headers = []): Response
    {
        $response = $this->view->render('partials/messages.html', $data, $status, [], [
            'theme' => 'admin',
            'render_partial' => true,
        ]);

        $response = $response->withHeader('HX-Retarget', '#page-messages')
            ->withHeader('HX-Reswap', 'innerHTML');

        foreach ($headers as $name => $value) {
            $response = $response->withHeader($name, $value);
        }

        return $response;
    }

    private function passwordError(Request $request, string $key, array $params = []): Response
    {
        if ($request->isHtmx()) {
            return $this->renderMessages($request, [
                'errors' => [$this->view->translate($key, $params)],
            ], 422);
        }

        return $this->errorResponse($request, 'invalid_request', 400);
    }

    private function passwordLength(string $value): int
    {
        if (function_exists('mb_strlen')) {
            return mb_strlen($value, 'UTF-8');
        }

        return strlen($value);
    }

    private function passwordWeak(string $value): bool
    {
        return !preg_match('/[A-Za-z]/', $value) || !preg_match('/\d/', $value);
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

    private function errorResponse(Request $request, string $code, int $status): Response
    {
        if ($request->isHtmx() || $request->wantsJson()) {
            return Response::json(['error' => $code], $status);
        }

        return new Response('Error', $status, [
            'Content-Type' => 'text/plain; charset=utf-8',
        ]);
    }
}
