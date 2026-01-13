<?php
declare(strict_types=1);

namespace Laas\Modules\Admin\Controller;

use Laas\Database\DatabaseManager;
use Laas\Database\Repositories\PermissionsRepository;
use Laas\Database\Repositories\RbacRepository;
use Laas\Database\Repositories\RolesRepository;
use Laas\Http\ErrorResponse;
use Laas\Http\Request;
use Laas\Http\Response;
use Laas\Support\AuditLogger;
use Laas\Support\Rbac\PermissionGrouper;
use Laas\View\View;
use Throwable;

final class RolesController
{
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
            return $this->notFound();
        }

        $role = $this->findRole($id);
        if ($role === null) {
            return $this->notFound();
        }

        $selected = $this->listRolePermissions($id) ?? [];
        return $this->renderForm($request, $role, $selected);
    }

    public function save(Request $request): Response
    {
        if (!$this->canManage($request)) {
            return $this->forbidden($request);
        }

        if ($this->db === null || !$this->db->healthCheck()) {
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

        $rolesRepo = new RolesRepository($this->db->pdo());
        $existingId = $rolesRepo->findIdByName($name);
        if ($existingId !== null && ($id === null || $existingId !== $id)) {
            $errors[] = $this->view->translate('admin.settings.error_invalid');
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

        $audit = new AuditLogger($this->db, $request->session());
        $actorId = $this->currentUserId($request);
        if ($id === null) {
            $id = $rolesRepo->create($name, $title !== '' ? $title : null);
            $audit->log('rbac.role.created', 'rbac_role', $id, [
                'actor_user_id' => $actorId,
                'target_role_id' => $id,
            ], $actorId, $request->ip());
        } else {
            $rolesRepo->update($id, $name, $title !== '' ? $title : null);
            $audit->log('rbac.role.updated', 'rbac_role', $id, [
                'actor_user_id' => $actorId,
                'target_role_id' => $id,
            ], $actorId, $request->ip());
        }

        $permRepo = new PermissionsRepository($this->db->pdo());
        $allPermissions = $permRepo->listAll();
        $nameToId = [];
        foreach ($allPermissions as $perm) {
            $permName = (string) ($perm['name'] ?? '');
            $permId = (int) ($perm['id'] ?? 0);
            if ($permName !== '' && $permId > 0) {
                $nameToId[$permName] = $permId;
            }
        }

        $permissionIds = [];
        foreach ($permissionNames as $permName) {
            $permName = (string) $permName;
            if (isset($nameToId[$permName])) {
                $permissionIds[] = $nameToId[$permName];
            }
        }

        $rbac = new RbacRepository($this->db->pdo());
        $diff = $rbac->setRolePermissions($id, $permissionIds);
        if (!empty($diff['added']) || !empty($diff['removed'])) {
            $addedNames = $this->resolvePermissionNames($allPermissions, $diff['added'] ?? []);
            $removedNames = $this->resolvePermissionNames($allPermissions, $diff['removed'] ?? []);
            $audit->log('rbac.role.permissions.updated', 'rbac_role', $id, [
                'actor_user_id' => $actorId,
                'target_role_id' => $id,
                'added_permissions' => $addedNames,
                'removed_permissions' => $removedNames,
            ], $actorId, $request->ip());
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

        if ($this->db === null || !$this->db->healthCheck()) {
            return $this->errorResponse($request, 'db_unavailable', 503);
        }

        $id = $this->readId($request);
        if ($id === null) {
            return $this->errorResponse($request, 'invalid_request', 400);
        }

        $rolesRepo = new RolesRepository($this->db->pdo());
        $role = $rolesRepo->findById($id);
        if ($role === null) {
            return $this->errorResponse($request, 'not_found', 404);
        }

        $rolesRepo->delete($id);
        (new AuditLogger($this->db, $request->session()))->log('rbac.role.deleted', 'rbac_role', $id, [
            'actor_user_id' => $this->currentUserId($request),
            'target_role_id' => $id,
        ], $this->currentUserId($request), $request->ip());

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
            return $this->notFound();
        }

        $role = $this->findRole($id);
        if ($role === null) {
            return $this->notFound();
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

        if ($this->db === null || !$this->db->healthCheck()) {
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

        $rolesRepo = new RolesRepository($this->db->pdo());
        $newName = $this->uniqueCloneName((string) ($role['name'] ?? ''), $rolesRepo);
        $newId = $rolesRepo->create($newName, (string) ($role['title'] ?? null));

        $permissions = $this->listRolePermissions($id) ?? [];
        $permRepo = new PermissionsRepository($this->db->pdo());
        $allPermissions = $permRepo->listAll();
        $nameToId = [];
        foreach ($allPermissions as $perm) {
            $permName = (string) ($perm['name'] ?? '');
            $permId = (int) ($perm['id'] ?? 0);
            if ($permName !== '' && $permId > 0) {
                $nameToId[$permName] = $permId;
            }
        }

        $permissionIds = [];
        foreach ($permissions as $permName) {
            if (isset($nameToId[$permName])) {
                $permissionIds[] = $nameToId[$permName];
            }
        }

        $rbac = new RbacRepository($this->db->pdo());
        $rbac->setRolePermissions($newId, $permissionIds);

        (new AuditLogger($this->db, $request->session()))->log('rbac.role.cloned', 'rbac_role', $newId, [
            'actor_user_id' => $this->currentUserId($request),
            'source_role_id' => $id,
            'new_role_id' => $newId,
            'permission_count' => count($permissionIds),
        ], $this->currentUserId($request), $request->ip());

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
        if ($this->db === null || !$this->db->healthCheck()) {
            return null;
        }

        try {
            $repo = new RolesRepository($this->db->pdo());
            return $repo->listAll();
        } catch (Throwable) {
            return null;
        }
    }

    private function findRole(int $id): ?array
    {
        if ($this->db === null || !$this->db->healthCheck()) {
            return null;
        }

        try {
            $repo = new RolesRepository($this->db->pdo());
            return $repo->findById($id);
        } catch (Throwable) {
            return null;
        }
    }

    private function listPermissions(): ?array
    {
        if ($this->db === null || !$this->db->healthCheck()) {
            return null;
        }

        try {
            $repo = new PermissionsRepository($this->db->pdo());
            return $repo->listAll();
        } catch (Throwable) {
            return null;
        }
    }

    /** @return array<int, string>|null */
    private function listRolePermissions(int $roleId): ?array
    {
        if ($this->db === null || !$this->db->healthCheck()) {
            return null;
        }

        try {
            $rbac = new RbacRepository($this->db->pdo());
            return $rbac->listRolePermissions($roleId);
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

    private function uniqueCloneName(string $baseName, RolesRepository $repo): string
    {
        $base = $baseName !== '' ? $baseName : 'role';
        $name = $base . ' (copy)';
        $suffix = 2;
        while ($repo->findIdByName($name) !== null) {
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
        if ($this->db === null || !$this->db->healthCheck()) {
            return false;
        }

        $userId = $this->currentUserId($request);
        if ($userId === null) {
            return false;
        }

        try {
            $rbac = new RbacRepository($this->db->pdo());
            return $rbac->userHasPermission($userId, 'rbac.manage');
        } catch (Throwable) {
            return false;
        }
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
        if ($request->wantsJson()) {
            return ErrorResponse::respond($request, 'forbidden', [], 403, [], 'admin.roles');
        }

        return $this->view->render('pages/403.html', [], 403, [], [
            'theme' => 'admin',
        ]);
    }

    private function errorResponse(Request $request, string $code, int $status): Response
    {
        if ($request->isHtmx() || $request->wantsJson()) {
            return ErrorResponse::respond($request, $code, [], $status, [], 'admin.roles');
        }

        return new Response('Error', $status, [
            'Content-Type' => 'text/plain; charset=utf-8',
        ]);
    }

    private function notFound(): Response
    {
        return new Response('Not Found', 404, [
            'Content-Type' => 'text/plain; charset=utf-8',
        ]);
    }
}
