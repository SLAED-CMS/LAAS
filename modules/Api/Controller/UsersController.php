<?php
declare(strict_types=1);

namespace Laas\Modules\Api\Controller;

use Laas\Api\ApiPagination;
use Laas\Api\ApiResponse;
use Laas\Core\Container\Container;
use Laas\Domain\Rbac\RbacServiceInterface;
use Laas\Domain\Users\UsersReadServiceInterface;
use Laas\Http\Request;
use Laas\Http\Response;
use Throwable;
use Laas\View\View;

final class UsersController
{
    public function __construct(
        private ?View $view = null,
        private ?UsersReadServiceInterface $usersService = null,
        private ?Container $container = null,
        private ?RbacServiceInterface $rbacService = null
    ) {
    }

    public function index(Request $request): Response
    {
        $service = $this->usersService();
        if ($service === null) {
            return ApiResponse::error('service_unavailable', 'Service Unavailable', [], 503);
        }

        if (!$this->canView($request)) {
            return ApiResponse::error('forbidden', 'Forbidden', [], 403);
        }

        $page = ApiPagination::page($request->query('page'));
        $perPage = ApiPagination::perPage($request->query('per_page'));
        $offset = ($page - 1) * $perPage;

        try {
            $rows = $service->list([
                'limit' => $perPage,
                'offset' => $offset,
            ]);
            $total = $service->count();
        } catch (Throwable) {
            return ApiResponse::error('service_unavailable', 'Service Unavailable', [], 503);
        }

        $items = array_map([$this, 'mapUser'], $rows);
        $meta = ApiPagination::meta($page, $perPage, $total);

        return ApiResponse::ok($items, $meta);
    }

    public function show(Request $request, array $params = []): Response
    {
        $service = $this->usersService();
        if ($service === null) {
            return ApiResponse::error('service_unavailable', 'Service Unavailable', [], 503);
        }

        if (!$this->canView($request)) {
            return ApiResponse::error('forbidden', 'Forbidden', [], 403);
        }

        $id = isset($params['id']) ? (int) $params['id'] : 0;
        if ($id <= 0) {
            return ApiResponse::error('not_found', 'Not Found', [], 404);
        }

        try {
            $user = $service->find($id);
        } catch (Throwable) {
            return ApiResponse::error('service_unavailable', 'Service Unavailable', [], 503);
        }
        if ($user === null) {
            return ApiResponse::error('not_found', 'Not Found', [], 404);
        }

        return ApiResponse::ok($this->mapUser($user));
    }

    private function canView(Request $request): bool
    {
        $user = $request->getAttribute('api.user');
        if (!is_array($user)) {
            return false;
        }

        $userId = (int) ($user['id'] ?? 0);
        if ($userId <= 0) {
            return false;
        }

        $rbac = $this->rbacService();
        if ($rbac === null) {
            return false;
        }

        return $rbac->userHasPermission($userId, 'users.view')
            || $rbac->userHasPermission($userId, 'users.manage');
    }

    private function usersService(): ?UsersReadServiceInterface
    {
        if ($this->usersService !== null) {
            return $this->usersService;
        }

        if ($this->container !== null) {
            try {
                $service = $this->container->get(UsersReadServiceInterface::class);
                if ($service instanceof UsersReadServiceInterface) {
                    $this->usersService = $service;
                    return $this->usersService;
                }
            } catch (Throwable) {
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
            } catch (Throwable) {
                return null;
            }
        }

        return null;
    }

    private function mapUser(array $row): array
    {
        return [
            'id' => (int) ($row['id'] ?? 0),
            'username' => (string) ($row['username'] ?? ''),
            'email' => (string) ($row['email'] ?? ''),
            'status' => (int) ($row['status'] ?? 0),
            'last_login_at' => (string) ($row['last_login_at'] ?? ''),
            'last_login_ip' => (string) ($row['last_login_ip'] ?? ''),
            'created_at' => (string) ($row['created_at'] ?? ''),
        ];
    }
}
