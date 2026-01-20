<?php
declare(strict_types=1);

namespace Laas\Modules\Admin\Controller;

use Laas\Core\Container\Container;
use Laas\Domain\Rbac\RbacServiceInterface;
use Laas\Domain\Users\UsersServiceInterface;
use Laas\Http\ErrorResponse;
use Laas\Http\Request;
use Laas\Http\Response;
use Laas\Support\Audit;
use Laas\Support\Rbac\RbacDiagnosticsService;
use Laas\View\View;

final class RbacDiagnosticsController
{
    public function __construct(
        private View $view,
        private ?UsersServiceInterface $usersService = null,
        private ?Container $container = null,
        private ?RbacServiceInterface $rbacService = null,
        private ?RbacDiagnosticsService $diagnosticsService = null
    ) {
    }

    public function index(Request $request): Response
    {
        if (!$this->canDiagnostics($request)) {
            return $this->forbidden($request);
        }

        $users = $this->listUsers();
        if ($users === null) {
            return $this->errorResponse($request, 'db_unavailable', 503);
        }

        $userId = $this->readUserId($request->query('user_id'));
        $result = $this->buildResult($userId, $request);

        if ($request->isHtmx()) {
            return $this->view->render('rbac/partials/user_result.html', [
                'result' => $result,
            ], 200, [], [
                'theme' => 'admin',
                'render_partial' => true,
            ]);
        }

        return $this->view->render('rbac/diagnostics.html', [
            'users' => $users,
            'selected_user_id' => $userId,
            'result' => $result,
        ], 200, [], [
            'theme' => 'admin',
        ]);
    }

    public function checkPermission(Request $request): Response
    {
        if (!$this->canDiagnostics($request)) {
            return $this->forbidden($request);
        }

        $userId = $this->readUserId($request->post('user_id'));
        $permName = trim((string) ($request->post('permission') ?? ''));
        if ($userId === null || $permName === '') {
            return $this->view->render('rbac/partials/perm_check.html', [
                'check' => null,
            ], 422, [], [
                'theme' => 'admin',
                'render_partial' => true,
            ]);
        }

        $service = $this->service();
        if ($service === null) {
            return $this->errorResponse($request, 'db_unavailable', 503);
        }

        $check = $service->explainPermission($userId, $permName);

        return $this->view->render('rbac/partials/perm_check.html', [
            'check' => $check,
            'permission' => $permName,
        ], 200, [], [
            'theme' => 'admin',
            'render_partial' => true,
        ]);
    }

    private function buildResult(?int $userId, Request $request): array
    {
        if ($userId === null) {
            return [
                'user_id' => null,
                'roles' => [],
                'groups' => [],
            ];
        }

        $service = $this->service();
        if ($service === null) {
            return [
                'user_id' => $userId,
                'roles' => [],
                'groups' => [],
            ];
        }

        $roles = $service->getUserRoles($userId);
        $perms = $service->getUserEffectivePermissions($userId);
        $groups = $perms['groups'] ?? [];

        Audit::log('rbac.diagnostics.viewed', 'rbac', $userId, [
            'target_user_id' => $userId,
            'actor_user_id' => $this->currentUserId($request),
            'actor_ip' => $request->ip(),
        ]);

        return [
            'user_id' => $userId,
            'roles' => $roles,
            'groups' => $groups,
        ];
    }

    private function listUsers(): ?array
    {
        $usersService = $this->usersService();
        if ($usersService === null) {
            return null;
        }

        try {
            $rows = $usersService->list([
                'limit' => 200,
                'offset' => 0,
            ]);
        } catch (\Throwable) {
            return null;
        }

        $users = [];
        foreach ($rows as $row) {
            $id = (int) ($row['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $username = (string) ($row['username'] ?? '');
            $email = (string) ($row['email'] ?? '');
            $label = $username !== '' ? $username : ('user-' . $id);
            if ($email !== '') {
                $label .= ' (' . $email . ')';
            }
            $users[] = [
                'id' => $id,
                'label' => $label,
            ];
        }

        return $users;
    }

    private function service(): ?RbacDiagnosticsService
    {
        if ($this->diagnosticsService !== null) {
            return $this->diagnosticsService;
        }

        if ($this->container !== null) {
            try {
                $service = $this->container->get(RbacDiagnosticsService::class);
                if ($service instanceof RbacDiagnosticsService) {
                    $this->diagnosticsService = $service;
                    return $this->diagnosticsService;
                }
            } catch (\Throwable) {
                return null;
            }
        }

        return null;
    }

    private function usersService(): ?UsersServiceInterface
    {
        if ($this->usersService !== null) {
            return $this->usersService;
        }

        if ($this->container !== null) {
            try {
                $service = $this->container->get(UsersServiceInterface::class);
                if ($service instanceof UsersServiceInterface) {
                    $this->usersService = $service;
                    return $this->usersService;
                }
            } catch (\Throwable) {
                return null;
            }
        }

        return null;
    }

    private function rbacService(): ?RbacServiceInterface
    {
        if ($this->rbacService !== null) {
            return $this->rbacService;
        }

        if ($this->container !== null) {
            try {
                $service = $this->container->get(RbacServiceInterface::class);
                if ($service instanceof RbacServiceInterface) {
                    $this->rbacService = $service;
                    return $this->rbacService;
                }
            } catch (\Throwable) {
                return null;
            }
        }

        return null;
    }

    private function readUserId(?string $raw): ?int
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        if (!ctype_digit($raw)) {
            return null;
        }
        $id = (int) $raw;
        return $id > 0 ? $id : null;
    }

    private function canDiagnostics(Request $request): bool
    {
        $userId = $this->currentUserId($request);
        if ($userId === null) {
            return false;
        }

        $rbac = $this->rbacService();
        if ($rbac === null) {
            return false;
        }

        return $rbac->userHasPermission($userId, 'rbac.diagnostics');
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
        return ErrorResponse::respondForRequest($request, 'forbidden', [], 403, [], 'admin.rbac_diagnostics');
    }

    private function errorResponse(Request $request, string $code, int $status): Response
    {
        return ErrorResponse::respondForRequest($request, $code, [], $status, [], 'admin.rbac_diagnostics');
    }
}
