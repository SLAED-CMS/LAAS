<?php
declare(strict_types=1);

namespace Laas\Modules\Admin\Controller;

use Laas\Core\Container\Container;
use Laas\Domain\Rbac\RbacServiceInterface;
use Laas\Http\ErrorResponse;
use Laas\Http\Request;
use Laas\Http\Response;
use Laas\Support\Audit;
use Laas\Support\Rbac\PermissionGrouper;
use Laas\View\View;
use Throwable;

final class RolesController
{
    public function __construct(
        private View $view,
        private ?RbacServiceInterface $rbacService = null,
        private ?Container $container = null
    ) {
    }

    public function index(Request $request): Response
    {
        if (!$this->canManage($request)) {
            return $this->forbidden($request);
        }

        $roles = $this->listRoles();
        if ($roles === null) {
            return $this->errorResponse($request, 'db_unavailable', 503);
        }

        return $this->view->render('pages/roles.html', [
            'roles' => $roles,
        ], 200, [], [
            'theme' => 'admin',
        ]);
    }

    public function createForm(Request $request): Response
    {
        if (!$this->canManage($request)) {
            return $this->forbidden($request);
        }

        $role = [
            'id' => 0,
            'name' => '',
            'title' => '',
        ];

        return $this->renderForm($request, $role, []);
    }

    public function editForm(Request $request, array $params = []): Response
    {
        if (!$this->canManage($request)) {
            return $this->forbidden($request);
        }

        $id = (int) ($params['id'] ?? 0);
        if ($id <= 0) {
            return $this->notFound($request);
        }

        $role = $this->findRole($id);
        if ($role === null) {
            return $this->notFound($request);
        }

        $selected = $this->listRolePermissions($id) ?? [];
        return $this->renderForm($request, $role, $selected);
    }

    public function save(Request $request): Response
    {
        if (!$this->canManage($request)) {
            return $this->forbidden($request);
        }

        $service = $this->rbacService();
        if ($service === null) {
            return $this->errorResponse($request, 'db_unavailable', 503);
        }

        $id = $this->readId($request);
        $name = trim((string) ($request->post('name') ?? ''));
        $title = trim((string) ($request->post('title') ?? ''));
        $permissionNames = $request->post('permissions');
        $permissionNames = is_array($permissionNames) ? $permissionNames : [];

        $errors = [];
        if ($name === '') {
            $errors[] = $this->view->translate('admin.settings.error_invalid');
        }

        try {
            $existingId = $service->findRoleIdByName($name);
            if ($existingId !== null && ($id === null || $existingId !== $id)) {
                $errors[] = $this->view->translate('admin.settings.error_invalid');
            }
        } catch (Throwable) {
            return $this->errorResponse($request, 'db_unavailable', 503);
        }

        if ($errors !== []) {
            if ($request->isHtmx()) {
                return $this->view->render('partials/messages.html', [
                    'errors' => $errors,
                ], 422, [], [
                    'theme' => 'admin',
                    'render_partial' => true,
                ]);
            }

            return $this->renderForm($request, [
                'id' => $id ?? 0,
                'name' => $name,
                'title' => $title,
            ], $permissionNames, 422, $errors);
        }

        $actorId = $this->currentUserId($request);
        try {
            if ($id === null) {
                $id = $service->createRole($name, $title !== '' ? $title : null);
                Audit::log('rbac.role.created', 'rbac_role', $id, [
                    'actor_user_id' => $actorId,
                    'target_role_id' => $id,
                ]);
            } else {
                $service->updateRole($id, $name, $title !== '' ? $title : null);
                Audit::log('rbac.role.updated', 'rbac_role', $id, [
                    'actor_user_id' => $actorId,
                    'target_role_id' => $id,
                ]);
            }

            $permissionIds = $service->resolvePermissionIdsByName($permissionNames);
            $diff = $service->setRolePermissions($id, $permissionIds);
        } catch (Throwable) {
            return $this->errorResponse($request, 'db_unavailable', 503);
        }

        if (!empty($diff['added']) || !empty($diff['removed'])) {
            $addedNames = $service->resolvePermissionNamesByIds($diff['added'] ?? []);
            $removedNames = $service->resolvePermissionNamesByIds($diff['removed'] ?? []);
            Audit::log('rbac.role.permissions.updated', 'rbac_role', $id, [
                'actor_user_id' => $actorId,
                'target_role_id' => $id,
                'added_permissions' => $addedNames,
                'removed_permissions' => $removedNames,
            ]);
        }

        if ($request->isHtmx()) {
            return new Response('', 200, [
                'HX-Redirect' => '/admin/users/roles/' . $id . '/edit',
            ]);
        }

        return new Response('', 302, [
            'Location' => '/admin/users/roles/' . $id . '/edit',
        ]);
    }

    public function delete(Request $request): Response
    {
        if (!$this->canManage($request)) {
            return $this->forbidden($request);
        }

        $service = $this->rbacService();
        if ($service === null) {
            return $this->errorResponse($request, 'db_unavailable', 503);
        }

        $id = $this->readId($request);
        if ($id === null) {
            return $this->errorResponse($request, 'invalid_request', 400);
        }

        $role = $this->findRole($id);
        if ($role === null) {
            return $this->errorResponse($request, 'not_found', 404);
        }

        try {
            $service->deleteRole($id);
        } catch (Throwable) {
            return $this->errorResponse($request, 'db_unavailable', 503);
        }

        Audit::log('rbac.role.deleted', 'rbac_role', $id, [
            'actor_user_id' => $this->currentUserId($request),
            'target_role_id' => $id,
        ]);

        if ($request->isHtmx()) {
            return new Response('', 200);
        }

        return new Response('', 302, [
            'Location' => '/admin/users/roles',
        ]);
    }

    public function cloneForm(Request $request, array $params = []): Response
    {
        if (!$this->canManage($request)) {
            return $this->forbidden($request);
        }

        $id = (int) ($params['id'] ?? 0);
        if ($id <= 0) {
            return $this->notFound($request);
        }

        $role = $this->findRole($id);
        if ($role === null) {
            return $this->notFound($request);
        }

        $permissions = $this->listRolePermissions($id) ?? [];
        return $this->view->render('pages/role_clone.html', [
            'role' => $role,
            'permission_count' => count($permissions),
        ], 200, [], [
            'theme' => 'admin',
        ]);
    }

    public function clone(Request $request, array $params = []): Response
    {
        if (!$this->canManage($request)) {
            return $this->forbidden($request);
        }

        $service = $this->rbacService();
        if ($service === null) {
            return $this->errorResponse($request, 'db_unavailable', 503);
        }

        $id = (int) ($params['id'] ?? 0);
        if ($id <= 0) {
            return $this->errorResponse($request, 'invalid_request', 400);
        }

        $role = $this->findRole($id);
        if ($role === null) {
            return $this->errorResponse($request, 'not_found', 404);
        }

        try {
            $newName = $this->uniqueCloneName((string) ($role['name'] ?? ''), $service);
            $newId = $service->createRole($newName, (string) ($role['title'] ?? null));
        } catch (Throwable) {
            return $this->errorResponse($request, 'db_unavailable', 503);
        }

        $permissions = $this->listRolePermissions($id) ?? [];
        $permissionIds = $service->resolvePermissionIdsByName($permissions);
        try {
            $service->setRolePermissions($newId, $permissionIds);
        } catch (Throwable) {
            return $this->errorResponse($request, 'db_unavailable', 503);
        }

        Audit::log('rbac.role.cloned', 'rbac_role', $newId, [
            'actor_user_id' => $this->currentUserId($request),
            'source_role_id' => $id,
            'new_role_id' => $newId,
            'permission_count' => count($permissionIds),
        ]);

        if ($request->isHtmx()) {
            return new Response('', 200, [
                'HX-Redirect' => '/admin/users/roles/' . $newId . '/edit',
            ]);
        }

        return new Response('', 302, [
            'Location' => '/admin/users/roles/' . $newId . '/edit',
        ]);
    }

    private function renderForm(
        Request $request,
        array $role,
        array $selectedPermissions,
        int $status = 200,
        array $errors = []
    ): Response {
        $permissions = $this->listPermissions();
        if ($permissions === null) {
            return $this->errorResponse($request, 'db_unavailable', 503);
        }

        $query = trim((string) ($request->query('q') ?? ''));
        if ($query !== '') {
            $permissions = array_values(array_filter($permissions, static function (array $perm) use ($query): bool {
                $name = (string) ($perm['name'] ?? '');
                $title = (string) ($perm['title'] ?? '');
                return stripos($name, $query) !== false || stripos($title, $query) !== false;
            }));
        }

        $grouper = new PermissionGrouper();
        $grouped = $grouper->group($permissions);
        $groups = [];
        foreach ($grouped as $prefix => $items) {
            $groups[] = [
                'key' => $prefix,
                'label' => $this->groupLabel($prefix),
                'permissions' => $this->mapPermissionRows($items, $selectedPermissions),
            ];
        }

        $permissionsUrl = !empty($role['id']) ? '/admin/users/roles/' . $role['id'] . '/edit' : '/admin/users/roles/new';
        $viewData = [
            'role' => $role,
            'groups' => $groups,
            'q' => $query,
            'errors' => $errors,
            'is_edit' => !empty($role['id']),
            'permissions_url' => $permissionsUrl,
        ];

        if ($request->isHtmx()) {
            return $this->view->render('partials/role_permissions.html', $viewData, $status, [], [
                'theme' => 'admin',
                'render_partial' => true,
            ]);
        }

        return $this->view->render('pages/role_form.html', $viewData, $status, [], [
            'theme' => 'admin',
        ]);
    }

    private function listRoles(): ?array
    {
        $service = $this->rbacService();
        if ($service === null) {
            return null;
        }

        try {
            return $service->listRoles();
        } catch (Throwable) {
            return null;
        }
    }

    private function findRole(int $id): ?array
    {
        $service = $this->rbacService();
        if ($service === null) {
            return null;
        }

        try {
            return $service->findRole($id);
        } catch (Throwable) {
            return null;
        }
    }

    private function listPermissions(): ?array
    {
        $service = $this->rbacService();
        if ($service === null) {
            return null;
        }

        try {
            return $service->listPermissions();
        } catch (Throwable) {
            return null;
        }
    }

    /** @return array<int, string>|null */
    private function listRolePermissions(int $roleId): ?array
    {
        $service = $this->rbacService();
        if ($service === null) {
            return null;
        }

        try {
            return $service->listRolePermissions($roleId);
        } catch (Throwable) {
            return null;
        }
    }

    private function mapPermissionRows(array $permissions, array $selected): array
    {
        $selected = array_fill_keys(array_map('strval', $selected), true);
        $rows = [];

        foreach ($permissions as $perm) {
            $name = (string) ($perm['name'] ?? '');
            if ($name === '') {
                continue;
            }
            $rows[] = [
                'name' => $name,
                'title' => (string) ($perm['title'] ?? ''),
                'checked' => isset($selected[$name]),
            ];
        }

        return $rows;
    }

    private function groupLabel(string $prefix): string
    {
        return match ($prefix) {
            'admin' => $this->view->translate('rbac.permissions.group.admin'),
            'pages' => $this->view->translate('rbac.permissions.group.pages'),
            'menus' => $this->view->translate('rbac.permissions.group.menus'),
            'media' => $this->view->translate('rbac.permissions.group.media'),
            'audit' => $this->view->translate('rbac.permissions.group.audit'),
            'debug' => $this->view->translate('rbac.permissions.group.debug'),
            'system' => $this->view->translate('rbac.permissions.group.system'),
            'users' => $this->view->translate('rbac.permissions.group.users'),
            default => $this->view->translate('rbac.permissions.group.other'),
        };
    }

    private function resolvePermissionNames(array $allPermissions, array $ids): array
    {
        $map = [];
        foreach ($allPermissions as $perm) {
            $id = (int) ($perm['id'] ?? 0);
            $name = (string) ($perm['name'] ?? '');
            if ($id > 0 && $name !== '') {
                $map[$id] = $name;
            }
        }

        $names = [];
        foreach ($ids as $id) {
            $id = (int) $id;
            if (isset($map[$id])) {
                $names[] = $map[$id];
            }
        }

        return $names;
    }

    private function uniqueCloneName(string $baseName, RbacServiceInterface $service): string
    {
        $base = $baseName !== '' ? $baseName : 'role';
        $name = $base . ' (copy)';
        $suffix = 2;
        while ($service->findRoleIdByName($name) !== null) {
            $name = $base . ' (copy ' . $suffix . ')';
            $suffix++;
        }
        return $name;
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

    private function canManage(Request $request): bool
    {
        $userId = $this->currentUserId($request);
        if ($userId === null) {
            return false;
        }

        $rbac = $this->rbacService();
        if ($rbac === null) {
            return false;
        }

        return $rbac->userHasPermission($userId, 'rbac.manage');
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

    private function forbidden(Request $request): Response
    {
        return ErrorResponse::respondForRequest($request, 'forbidden', [], 403, [], 'admin.roles');
    }

    private function errorResponse(Request $request, string $code, int $status): Response
    {
        return ErrorResponse::respondForRequest($request, $code, [], $status, [], 'admin.roles');
    }

    private function notFound(Request $request): Response
    {
        return ErrorResponse::respondForRequest($request, 'not_found', [], 404, [], 'admin.roles');
    }

    private function rbacService(): ?RbacServiceInterface
    {
        if ($this->rbacService !== null) {
            return $this->rbacService;
        }

        if ($this->container === null) {
            return null;
        }

        try {
            $service = $this->container->get(RbacServiceInterface::class);
            if ($service instanceof RbacServiceInterface) {
                $this->rbacService = $service;
                return $this->rbacService;
            }
        } catch (Throwable) {
            return null;
        }

        return null;
    }
}
