<?php
declare(strict_types=1);

namespace Laas\Modules\Admin\Controller;

use Laas\Database\DatabaseManager;
use Laas\Database\Repositories\RbacRepository;
use Laas\Database\Repositories\UsersRepository;
use Laas\Http\Request;
use Laas\Http\Response;
use Laas\View\View;
use Throwable;

final class UsersController
{
    public function __construct(
        private View $view,
        private ?DatabaseManager $db = null
    ) {
    }

    public function index(Request $request): Response
    {
        $repo = $this->getUsersRepository();
        $rbac = $this->getRbacRepository();
        if ($repo === null || $rbac === null) {
            return $this->errorResponse($request, 'db_unavailable', 503);
        }

        $currentUserId = $this->currentUserId();
        $users = $repo->list(100, 0);
        $rows = [];

        foreach ($users as $user) {
            $userId = (int) ($user['id'] ?? 0);
            if ($userId <= 0) {
                continue;
            }

            $isAdmin = $rbac->userHasRole($userId, 'admin');
            $rows[] = $this->mapUserRow($user, $isAdmin, $currentUserId);
        }

        return $this->view->render('pages/users.html', [
            'users' => $rows,
        ], 200, [], [
            'theme' => 'admin',
        ]);
    }

    public function toggleStatus(Request $request): Response
    {
        $userId = $this->readUserId($request);
        if ($userId === null) {
            return $this->errorResponse($request, 'invalid_request', 400);
        }

        $repo = $this->getUsersRepository();
        $rbac = $this->getRbacRepository();
        if ($repo === null || $rbac === null) {
            return $this->errorResponse($request, 'db_unavailable', 503);
        }

        $currentUserId = $this->currentUserId();
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

        $isAdmin = $rbac->userHasRole($userId, 'admin');
        $row = $this->mapUserRow($user, $isAdmin, $currentUserId);
        $row['status'] = $nextStatus;

        return $this->renderRow($request, $row);
    }

    public function toggleAdmin(Request $request): Response
    {
        $userId = $this->readUserId($request);
        if ($userId === null) {
            return $this->errorResponse($request, 'invalid_request', 400);
        }

        $repo = $this->getUsersRepository();
        $rbac = $this->getRbacRepository();
        if ($repo === null || $rbac === null) {
            return $this->errorResponse($request, 'db_unavailable', 503);
        }

        $currentUserId = $this->currentUserId();
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

        $row = $this->mapUserRow($user, !$isAdmin, $currentUserId);
        return $this->renderRow($request, $row);
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

    private function mapUserRow(array $user, bool $isAdmin, ?int $currentUserId): array
    {
        $id = (int) ($user['id'] ?? 0);
        $status = (int) ($user['status'] ?? 0);
        $protected = $currentUserId !== null && $id === $currentUserId;
        $lastLogin = (string) ($user['last_login_at'] ?? '');

        return [
            'id' => $id,
            'username' => (string) ($user['username'] ?? ''),
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
