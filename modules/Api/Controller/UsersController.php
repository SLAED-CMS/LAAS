<?php
declare(strict_types=1);

namespace Laas\Modules\Api\Controller;

use Laas\Api\ApiPagination;
use Laas\Api\ApiResponse;
use Laas\Database\DatabaseManager;
use Laas\Database\Repositories\RbacRepository;
use Laas\Database\Repositories\UsersRepository;
use Laas\Http\Request;
use Laas\Http\Response;
use Throwable;

final class UsersController
{
    public function __construct(private ?DatabaseManager $db = null)
    {
    }

    public function index(Request $request): Response
    {
        if ($this->db === null || !$this->db->healthCheck()) {
            return ApiResponse::error('service_unavailable', 'Service Unavailable', [], 503);
        }

        if (!$this->canView($request)) {
            return ApiResponse::error('forbidden', 'Forbidden', [], 403);
        }

        $page = ApiPagination::page($request->query('page'));
        $perPage = ApiPagination::perPage($request->query('per_page'));
        $offset = ($page - 1) * $perPage;

        $repo = new UsersRepository($this->db->pdo());
        $rows = $repo->list($perPage, $offset);
        $total = $repo->countAll();

        $items = array_map([$this, 'mapUser'], $rows);
        $meta = ApiPagination::meta($page, $perPage, $total);

        return ApiResponse::ok($items, $meta);
    }

    public function show(Request $request, array $params = []): Response
    {
        if ($this->db === null || !$this->db->healthCheck()) {
            return ApiResponse::error('service_unavailable', 'Service Unavailable', [], 503);
        }

        if (!$this->canView($request)) {
            return ApiResponse::error('forbidden', 'Forbidden', [], 403);
        }

        $id = isset($params['id']) ? (int) $params['id'] : 0;
        if ($id <= 0) {
            return ApiResponse::error('not_found', 'Not Found', [], 404);
        }

        $repo = new UsersRepository($this->db->pdo());
        $user = $repo->findById($id);
        if ($user === null) {
            return ApiResponse::error('not_found', 'Not Found', [], 404);
        }

        return ApiResponse::ok($this->mapUser($user));
    }

    private function canView(Request $request): bool
    {
        $user = $request->getAttribute('api.user');
        if (!is_array($user) || $this->db === null) {
            return false;
        }

        $userId = (int) ($user['id'] ?? 0);
        if ($userId <= 0) {
            return false;
        }

        try {
            $rbac = new RbacRepository($this->db->pdo());
            return $rbac->userHasPermission($userId, 'users.view')
                || $rbac->userHasPermission($userId, 'users.manage');
        } catch (Throwable) {
            return false;
        }
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
