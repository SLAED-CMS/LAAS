<?php
declare(strict_types=1);

namespace Laas\Modules\Admin\Controller;

use Laas\Core\Container\Container;
use Laas\Database\DatabaseManager;
use Laas\Database\Repositories\RbacRepository;
use Laas\Domain\Users\UsersService;
use Laas\Http\Contract\ContractResponse;
use Laas\Http\ErrorResponse;
use Laas\Http\Request;
use Laas\Http\Response;
use Laas\Support\AuditLogger;
use Laas\Support\Search\Highlighter;
use Laas\Support\Search\SearchNormalizer;
use Laas\Support\Search\SearchQuery;
use Laas\Ui\UiTokenMapper;
use Laas\View\View;
use Throwable;

final class UsersController
{
    private const MIN_PASSWORD_LENGTH = 8;

    public function __construct(
        private View $view,
        private ?DatabaseManager $db = null,
        private ?UsersService $usersService = null,
        private ?Container $container = null
    ) {
    }

    public function index(Request $request): Response
    {
        if (!$this->canManage($request)) {
            if ($request->wantsJson()) {
                return $this->contractForbidden('admin.users.index');
            }
            return $this->forbidden($request);
        }

        $service = $this->service();
        if ($service === null) {
            if ($request->wantsJson()) {
                return $this->contractServiceUnavailable('admin.users.index');
            }
            return $this->errorResponse($request, 'db_unavailable', 503);
        }

        $query = SearchNormalizer::normalize((string) ($request->query('q') ?? ''));
        if (SearchNormalizer::isTooShort($query)) {
            $message = $this->view->translate('search.too_short');
            if ($request->wantsJson()) {
                return $this->contractValidationError('admin.users.index', [
                    'q' => ['too_short'],
                ]);
            }
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
        $limit = 100;
        $offset = 0;
        if ($query !== '') {
            $search = new SearchQuery($query, 50, 1, 'users');
            $limit = $search->limit;
            $offset = $search->offset;
            $users = $service->list([
                'query' => $search->q,
                'limit' => $search->limit,
                'offset' => $search->offset,
            ]);
        } else {
            $users = $service->list([
                'limit' => 100,
                'offset' => 0,
            ]);
        }
        $rows = [];
        $userIds = [];
        foreach ($users as $user) {
            $userId = (int) ($user['id'] ?? 0);
            if ($userId > 0) {
                $userIds[] = $userId;
            }
        }

        $rolesMap = $service->rolesForUsers($userIds);
        $contractItems = [];
        foreach ($users as $user) {
            $userId = (int) ($user['id'] ?? 0);
            if ($userId <= 0) {
                continue;
            }

            $roles = $rolesMap[$userId] ?? [];
            $isAdmin = in_array('admin', $roles, true);
            $rows[] = $this->mapUserRow($user, $isAdmin, $currentUserId, $query);
            $contractItems[] = $this->mapUserContract($user, $roles);
        }

        $viewData = [
            'users' => $rows,
            'q' => $query,
            'errors' => [],
        ];

        if ($request->wantsJson()) {
            $total = $service->count(['query' => $query]);
            return ContractResponse::ok([
                'items' => $contractItems,
                'pagination' => [
                    'limit' => $limit,
                    'offset' => $offset,
                    'total' => $total,
                ],
            ], [
                'route' => 'admin.users.index',
            ]);
        }

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
            if ($request->wantsJson()) {
                return $this->contractForbidden('admin.users.toggle');
            }
            return $this->forbidden($request);
        }

        $userId = $this->readUserId($request);
        if ($userId === null) {
            if ($request->wantsJson()) {
                return $this->contractValidationError('admin.users.toggle', [
                    'user_id' => ['invalid'],
                ]);
            }
            return $this->errorResponse($request, 'invalid_request', 400);
        }

        $service = $this->service();
        if ($service === null) {
            if ($request->wantsJson()) {
                return $this->contractServiceUnavailable('admin.users.toggle');
            }
            return $this->errorResponse($request, 'db_unavailable', 503);
        }

        $currentUserId = $this->currentUserId($request);
        if ($currentUserId !== null && $userId === $currentUserId) {
            if ($request->wantsJson()) {
                return $this->contractValidationError('admin.users.toggle', [
                    'user_id' => ['self_protected'],
                ]);
            }
            return $this->errorResponse($request, 'self_protected', 400);
        }

        $user = $service->find($userId);
        if ($user === null) {
            if ($request->wantsJson()) {
                return $this->contractValidationError('admin.users.toggle', [
                    'user_id' => ['not_found'],
                ]);
            }
            return $this->errorResponse($request, 'not_found', 404);
        }

        $currentStatus = (int) ($user['status'] ?? 0);
        $nextStatus = $currentStatus === 1 ? 0 : 1;
        $service->setStatus($userId, $nextStatus);

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

        $isAdmin = $service->isAdmin($userId);
        $row = $this->mapUserRow($user, $isAdmin, $currentUserId);
        $row['status'] = $nextStatus;

        if ($request->wantsJson()) {
            return ContractResponse::ok([
                'id' => $userId,
                'active' => $nextStatus === 1,
            ], [
                'route' => 'admin.users.toggle',
                'status' => 'ok',
            ]);
        }

        $response = $this->renderRow($request, $row);
        if ($request->isHtmx()) {
            $messageKey = $nextStatus === 1 ? 'admin.users.enable' : 'admin.users.disable';
            return $this->withSuccessTrigger($response, $messageKey);
        }
        return $response;
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

        $service = $this->service();
        if ($service === null) {
            return $this->errorResponse($request, 'db_unavailable', 503);
        }

        $currentUserId = $this->currentUserId($request);
        if ($currentUserId !== null && $userId === $currentUserId) {
            return $this->errorResponse($request, 'self_protected', 400);
        }

        $user = $service->find($userId);
        if ($user === null) {
            return $this->errorResponse($request, 'not_found', 404);
        }

        $isAdmin = $service->isAdmin($userId);
        $service->setAdminRole($userId, !$isAdmin);

        $actorId = $currentUserId;
        $audit = new AuditLogger($this->db, $request->session());
        $audit->log('rbac.user.roles.updated', 'user', $userId, [
            'actor_user_id' => $actorId,
            'target_user_id' => $userId,
            'added_roles' => $isAdmin ? [] : ['admin'],
            'removed_roles' => $isAdmin ? ['admin'] : [],
        ], $actorId, $request->ip());

        $row = $this->mapUserRow($user, !$isAdmin, $currentUserId);
        $response = $this->renderRow($request, $row);
        if ($request->isHtmx()) {
            $messageKey = $isAdmin ? 'admin.users.remove_admin' : 'admin.users.make_admin';
            return $this->withSuccessTrigger($response, $messageKey);
        }
        return $response;
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

        $service = $this->service();
        if ($service === null) {
            return $this->errorResponse($request, 'db_unavailable', 503);
        }

        $user = $service->find($userId);
        if ($user === null) {
            return $this->errorResponse($request, 'not_found', 404);
        }

        $hash = password_hash($password, PASSWORD_DEFAULT);
        if ($hash === false) {
            return $this->errorResponse($request, 'invalid_request', 400);
        }

        $service->setPasswordHash($userId, $hash);

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
            $response = $this->renderMessages($request, [
                'success' => $this->view->translate('admin.users.password_changed'),
            ]);
            return $this->withSuccessTrigger($response, 'admin.users.password_changed');
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

        $service = $this->service();
        if ($service === null || $this->db === null || !$this->db->healthCheck()) {
            return $this->errorResponse($request, 'db_unavailable', 503);
        }

        $currentUserId = $this->currentUserId($request);
        if ($currentUserId !== null && $userId === $currentUserId) {
            return $this->errorResponse($request, 'self_protected', 400);
        }

        $user = $service->find($userId);
        if ($user === null) {
            return $this->errorResponse($request, 'not_found', 404);
        }

        $service->delete($userId);

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
            $response = $this->renderMessages($request, [
                'success' => $this->view->translate('admin.users.deleted'),
            ], 200, [
                'HX-Trigger' => 'users:refresh',
            ]);
            return $this->withSuccessTrigger($response, 'admin.users.deleted');
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
        $ui = UiTokenMapper::mapUserRow([
            'status' => $status,
        ]);

        return [
            'id' => $id,
            'username' => $username,
            'username_segments' => Highlighter::segments($username, $query ?? ''),
            'email' => $email,
            'email_segments' => Highlighter::segments($email, $query ?? ''),
            'status' => $status,
            'is_admin' => $isAdmin,
            'protected' => $protected,
            'last_login_at' => $lastLogin !== '' ? $lastLogin : '-',
            'ui' => $ui,
        ];
    }

    private function mapUserContract(array $user, array $roles): array
    {
        $id = (int) ($user['id'] ?? 0);
        $username = (string) ($user['username'] ?? '');
        $createdAt = (string) ($user['created_at'] ?? '');
        $status = (int) ($user['status'] ?? 0);
        $normalizedRoles = [];
        foreach ($roles as $role) {
            if ($role !== '') {
                $normalizedRoles[] = (string) $role;
            }
        }

        return [
            'id' => $id,
            'username' => $username,
            'roles' => $normalizedRoles,
            'active' => $status === 1,
            'created_at' => $createdAt,
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
        return ErrorResponse::respondForRequest($request, 'forbidden', [], 403, [], 'admin.users');
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

    private function errorResponse(Request $request, string $code, int $status): Response
    {
        return ErrorResponse::respondForRequest($request, $code, [], $status, [], 'admin.users');
    }

    private function contractValidationError(string $route, array $fields = []): Response
    {
        return ContractResponse::error('validation_failed', [
            'route' => $route,
        ], 422, $fields);
    }

    private function contractForbidden(string $route): Response
    {
        return ContractResponse::error('forbidden', [
            'route' => $route,
        ], 403);
    }

    private function contractServiceUnavailable(string $route): Response
    {
        return ContractResponse::error('service_unavailable', [
            'route' => $route,
        ], 503);
    }

    private function withSuccessTrigger(Response $response, string $messageKey): Response
    {
        return $response->withToastSuccess($messageKey, $this->view->translate($messageKey));
    }

    private function service(): ?UsersService
    {
        if ($this->usersService !== null) {
            return $this->usersService;
        }

        if ($this->container !== null) {
            try {
                $service = $this->container->get(UsersService::class);
                if ($service instanceof UsersService) {
                    $this->usersService = $service;
                    return $this->usersService;
                }
            } catch (Throwable) {
                return null;
            }
        }

        return null;
    }
}
