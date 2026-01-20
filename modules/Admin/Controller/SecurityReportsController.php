<?php
declare(strict_types=1);

namespace Laas\Modules\Admin\Controller;

use Laas\Core\Container\Container;
use Laas\Domain\Rbac\RbacServiceInterface;
use Laas\Domain\Security\SecurityReportsServiceInterface;
use Laas\Domain\Users\UsersServiceInterface;
use Laas\Http\Contract\ContractResponse;
use Laas\Http\ErrorResponse;
use Laas\Http\Request;
use Laas\Http\Response;
use Laas\Http\UiToast;
use Laas\Support\Audit;
use Laas\View\View;
use Throwable;

final class SecurityReportsController
{
    private ?UsersServiceInterface $usersService = null;

    public function __construct(
        private View $view,
        private ?SecurityReportsServiceInterface $reportsService = null,
        private ?Container $container = null
    ) {
    }

    public function index(Request $request): Response
    {
        if (!$this->canView($request)) {
            return $this->forbidden($request, 'admin.security_reports.index');
        }

        $service = $this->service();
        if ($service === null) {
            return $this->errorResponse($request, 'db_unavailable', 503, 'admin.security_reports.index');
        }

        $filters = $this->readFilters($request);
        $page = $this->readPage($request);
        $limit = 100;
        try {
            $total = $service->count($filters);
            $totalPages = max(1, (int) ceil($total / $limit));
            if ($page > $totalPages) {
                $page = $totalPages;
            }
            $offset = ($page - 1) * $limit;

            $rows = $service->list(array_merge($filters, [
                'limit' => $limit,
                'offset' => $offset,
            ]));
            $canManage = $this->canManage($request);
            $items = array_map(function (array $row) use ($canManage): array {
                return $this->mapRowForView($row, $canManage);
            }, $rows);

            $statusCounts = $this->countStatusFilters($service, $filters);
            $typeCounts = $this->countTypeFilters($service, $filters);
        } catch (Throwable) {
            return $this->errorResponse($request, 'db_unavailable', 503, 'admin.security_reports.index');
        }

        if ($request->wantsJson()) {
            return ContractResponse::ok([
                'items' => $this->mapRowsForJson($rows),
                'counts' => [
                    'total' => $total,
                    'page' => $page,
                    'total_pages' => $totalPages,
                ],
            ], [
                'route' => 'admin.security_reports.index',
            ]);
        }

        $viewData = [
            'reports' => $items,
            'filters' => $filters,
            'status_options' => $this->statusOptions($filters['status']),
            'type_options' => $this->typeOptions($filters['type']),
            'pagination' => [
                'page' => $page,
                'total_pages' => $totalPages,
                'has_prev' => $page > 1,
                'has_next' => $page < $totalPages,
                'prev_url' => $page > 1 ? $this->buildPageUrl($page - 1, $filters) : '#',
                'next_url' => $page < $totalPages ? $this->buildPageUrl($page + 1, $filters) : '#',
            ],
            'stats' => [
                'total' => $total,
                'new' => $statusCounts['new'],
                'triaged' => $statusCounts['triaged'],
                'ignored' => $statusCounts['ignored'],
                'csp' => $typeCounts['csp'],
                'other' => $this->countOtherTypes($typeCounts),
            ],
        ];

        if ($request->isHtmx()) {
            return $this->view->render('partials/security_reports_table.html', $viewData, 200, [], [
                'theme' => 'admin',
                'render_partial' => true,
            ]);
        }

        return $this->view->render('pages/security_reports.html', $viewData, 200, [], [
            'theme' => 'admin',
        ]);
    }

    public function show(Request $request, array $params = []): Response
    {
        if (!$this->canView($request)) {
            return $this->forbidden($request, 'admin.security_reports.show');
        }

        $id = $this->readId($params);
        if ($id === null) {
            return $this->notFound($request, 'admin.security_reports.show');
        }

        $service = $this->service();
        if ($service === null) {
            return $this->errorResponse($request, 'db_unavailable', 503, 'admin.security_reports.show');
        }

        $row = $service->find((string) $id);
        if ($row === null) {
            return $this->notFound($request, 'admin.security_reports.show');
        }

        if ($request->wantsJson()) {
            return ContractResponse::ok([
                'report' => $this->mapRowForJson($row),
            ], [
                'route' => 'admin.security_reports.show',
            ]);
        }

        $viewData = [
            'report' => $this->mapRowForView($row, $this->canManage($request)),
        ];

        return $this->view->render('pages/security_report.html', $viewData, 200, [], [
            'theme' => 'admin',
        ]);
    }

    public function triage(Request $request, array $params = []): Response
    {
        if (!$this->canManage($request)) {
            return $this->forbidden($request, 'admin.security_reports.triage');
        }

        return $this->updateStatus($request, $params, 'triaged', 'admin.security_reports.triage', 'admin.security_reports.updated_ok');
    }

    public function ignore(Request $request, array $params = []): Response
    {
        if (!$this->canManage($request)) {
            return $this->forbidden($request, 'admin.security_reports.ignore');
        }

        return $this->updateStatus($request, $params, 'ignored', 'admin.security_reports.ignore', 'admin.security_reports.updated_ok');
    }

    public function delete(Request $request, array $params = []): Response
    {
        if (!$this->canManage($request)) {
            return $this->forbidden($request, 'admin.security_reports.delete');
        }

        $id = $this->readId($params);
        if ($id === null) {
            return $this->notFound($request, 'admin.security_reports.delete');
        }

        $service = $this->service();
        if ($service === null) {
            return $this->errorResponse($request, 'db_unavailable', 503, 'admin.security_reports.delete');
        }

        $row = $service->find((string) $id);
        if ($row === null) {
            return $this->notFound($request, 'admin.security_reports.delete');
        }

        $service->delete($id);
        $this->logAudit($request, $id, 'deleted');

        if ($request->wantsJson()) {
            UiToast::registerInfo($this->view->translate('admin.security_reports.deleted'), 'admin.security_reports.deleted');
            return ContractResponse::ok([
                'deleted' => true,
                'id' => $id,
            ], [
                'route' => 'admin.security_reports.delete',
            ]);
        }

        if ($request->isHtmx()) {
            $viewData = [
                'report' => $this->mapRowForView($row, false, true, true),
            ];
            $response = $this->view->render('partials/security_report_row.html', $viewData, 200, [], [
                'theme' => 'admin',
                'render_partial' => true,
            ]);
            return $this->withSuccessTrigger($response, 'admin.security_reports.deleted');
        }

        return new Response('', 303, [
            'Location' => '/admin/security-reports',
        ]);
    }

    private function updateStatus(Request $request, array $params, string $status, string $route, string $toastKey): Response
    {
        $id = $this->readId($params);
        if ($id === null) {
            return $this->notFound($request, $route);
        }

        $service = $this->service();
        if ($service === null) {
            return $this->errorResponse($request, 'db_unavailable', 503, $route);
        }

        $row = $service->find((string) $id);
        if ($row === null) {
            return $this->notFound($request, $route);
        }

        $updated = $service->updateStatus($id, $status);
        if (!$updated) {
            return $this->notFound($request, $route);
        }

        $this->logAudit($request, $id, $status);
        $fresh = $service->find((string) $id) ?? $row;

        if ($request->wantsJson()) {
            UiToast::registerInfo($this->view->translate($toastKey), $toastKey);
            return ContractResponse::ok([
                'report' => $this->mapRowForJson($fresh),
            ], [
                'route' => $route,
            ]);
        }

        if ($request->isHtmx()) {
            $viewData = [
                'report' => $this->mapRowForView($fresh, true, true),
            ];
            $response = $this->view->render('partials/security_report_row.html', $viewData, 200, [], [
                'theme' => 'admin',
                'render_partial' => true,
            ]);
            return $this->withSuccessTrigger($response, $toastKey);
        }

        return new Response('', 303, [
            'Location' => '/admin/security-reports',
        ]);
    }

    private function canView(Request $request): bool
    {
        return $this->hasPermission($request, 'security_reports.view');
    }

    private function canManage(Request $request): bool
    {
        return $this->hasPermission($request, 'security_reports.manage');
    }

    private function hasPermission(Request $request, string $permission): bool
    {
        $userId = $this->currentUserId($request);
        if ($userId === null) {
            return false;
        }

        $rbac = $this->rbacService();
        if ($rbac === null) {
            return false;
        }

        return $rbac->userHasPermission($userId, $permission);
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

    private function readId(array $params): ?int
    {
        $raw = $params['id'] ?? null;
        if ($raw === null || $raw === '') {
            return null;
        }
        if (is_int($raw)) {
            return $raw > 0 ? $raw : null;
        }
        if (!is_string($raw) || !ctype_digit($raw)) {
            return null;
        }

        $id = (int) $raw;
        return $id > 0 ? $id : null;
    }

    private function readPage(Request $request): int
    {
        $raw = $request->query('page');
        if ($raw === null || $raw === '') {
            return 1;
        }

        if (!ctype_digit($raw)) {
            return 1;
        }

        $page = (int) $raw;
        return $page > 0 ? $page : 1;
    }

    /** @return array{type: string, status: string, search: string} */
    private function readFilters(Request $request): array
    {
        $type = trim((string) ($request->query('type') ?? 'all'));
        if (!in_array($type, ['all', 'csp', 'other'], true)) {
            $type = 'all';
        }

        $status = trim((string) ($request->query('status') ?? 'all'));
        if (!in_array($status, ['all', 'new', 'triaged', 'ignored'], true)) {
            $status = 'all';
        }

        $search = trim((string) ($request->query('search') ?? ''));

        return [
            'type' => $type,
            'status' => $status,
            'search' => $search,
        ];
    }

    /** @return array<int, array<string, string>> */
    private function statusOptions(string $selected): array
    {
        $options = [
            'all' => 'admin.security_reports.filter.all',
            'new' => 'admin.security_reports.status.new',
            'triaged' => 'admin.security_reports.status.triaged',
            'ignored' => 'admin.security_reports.status.ignored',
        ];

        $out = [];
        foreach ($options as $value => $label) {
            $out[] = [
                'value' => $value,
                'label' => $this->view->translate($label),
                'selected' => $value === $selected ? 'selected' : '',
            ];
        }

        return $out;
    }

    /** @return array<int, array<string, string>> */
    private function typeOptions(string $selected): array
    {
        $options = [
            'all' => 'All',
            'csp' => 'CSP',
            'other' => 'Other',
        ];

        $out = [];
        foreach ($options as $value => $label) {
            $out[] = [
                'value' => $value,
                'label' => $label,
                'selected' => $value === $selected ? 'selected' : '',
            ];
        }

        return $out;
    }

    private function countOtherTypes(array $typeCounts): int
    {
        $sum = 0;
        foreach ($typeCounts as $type => $count) {
            if ($type === 'csp') {
                continue;
            }
            $sum += (int) $count;
        }

        return $sum;
    }

    private function service(): ?SecurityReportsServiceInterface
    {
        if ($this->reportsService !== null) {
            return $this->reportsService;
        }

        if ($this->container !== null) {
            try {
                $service = $this->container->get(SecurityReportsServiceInterface::class);
                if ($service instanceof SecurityReportsServiceInterface) {
                    $this->reportsService = $service;
                    return $this->reportsService;
                }
            } catch (Throwable) {
                return null;
            }
        }

        return null;
    }

    private function rbacService(): ?RbacServiceInterface
    {
        if ($this->container === null) {
            return null;
        }

        try {
            $service = $this->container->get(RbacServiceInterface::class);
            return $service instanceof RbacServiceInterface ? $service : null;
        } catch (Throwable) {
            return null;
        }
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
            } catch (Throwable) {
                return null;
            }
        }

        return null;
    }

    /** @return array{new: int, triaged: int, ignored: int} */
    private function countStatusFilters(SecurityReportsServiceInterface $service, array $filters): array
    {
        $status = $filters['status'] ?? 'all';
        $statusCounts = [
            'new' => 0,
            'triaged' => 0,
            'ignored' => 0,
        ];

        if (in_array($status, ['new', 'triaged', 'ignored'], true)) {
            $statusCounts[$status] = $service->count(array_merge($filters, ['status' => $status]));
            return $statusCounts;
        }

        foreach (['new', 'triaged', 'ignored'] as $item) {
            $statusCounts[$item] = $service->count(array_merge($filters, ['status' => $item]));
        }

        return $statusCounts;
    }

    /** @return array{csp: int, other: int} */
    private function countTypeFilters(SecurityReportsServiceInterface $service, array $filters): array
    {
        $type = $filters['type'] ?? 'all';
        $typeCounts = [
            'csp' => 0,
            'other' => 0,
        ];

        if (in_array($type, ['csp', 'other'], true)) {
            $typeCounts[$type] = $service->count(array_merge($filters, ['type' => $type]));
            return $typeCounts;
        }

        $typeCounts['csp'] = $service->count(array_merge($filters, ['type' => 'csp']));
        $typeCounts['other'] = $service->count(array_merge($filters, ['type' => 'other']));

        return $typeCounts;
    }

    private function buildPageUrl(int $page, array $filters): string
    {
        $params = [];
        foreach ($filters as $key => $value) {
            if ($value !== '' && $value !== 'all') {
                $params[$key] = $value;
            }
        }
        $params['page'] = $page;
        return '/admin/security-reports?' . http_build_query($params);
    }

    private function mapRowForView(array $row, bool $canManage, bool $flash = false, bool $deleted = false): array
    {
        $status = (string) ($row['status'] ?? 'new');
        $type = (string) ($row['type'] ?? '');
        $createdAt = (string) ($row['created_at'] ?? '');
        $ui = $this->severityTokens($row);

        return [
            'id' => (int) ($row['id'] ?? 0),
            'type' => $type,
            'type_label' => $type !== '' ? strtoupper($type) : '-',
            'status' => $status,
            'status_label' => $this->statusLabel($status),
            'document_uri' => $this->emptyDash($row['document_uri'] ?? null),
            'violated_directive' => $this->emptyDash($row['violated_directive'] ?? null),
            'blocked_uri' => $this->emptyDash($row['blocked_uri'] ?? null),
            'user_agent' => $this->emptyDash($row['user_agent'] ?? null),
            'ip' => $this->emptyDash($row['ip'] ?? null),
            'request_id' => $this->emptyDash($row['request_id'] ?? null),
            'created_at' => $createdAt !== '' ? $createdAt : '-',
            'updated_at' => $this->emptyDash($row['updated_at'] ?? null),
            'triaged_at' => $this->emptyDash($row['triaged_at'] ?? null),
            'ignored_at' => $this->emptyDash($row['ignored_at'] ?? null),
            'can_manage' => $canManage,
            'flash' => $flash ? 1 : 0,
            'deleted' => $deleted ? 1 : 0,
            'ui' => $ui,
        ];
    }

    private function mapRowsForJson(array $rows): array
    {
        return array_map(fn(array $row): array => $this->mapRowForJson($row), $rows);
    }

    private function mapRowForJson(array $row): array
    {
        return [
            'id' => (int) ($row['id'] ?? 0),
            'type' => (string) ($row['type'] ?? ''),
            'status' => (string) ($row['status'] ?? ''),
            'document_uri' => (string) ($row['document_uri'] ?? ''),
            'violated_directive' => (string) ($row['violated_directive'] ?? ''),
            'blocked_uri' => (string) ($row['blocked_uri'] ?? ''),
            'user_agent' => (string) ($row['user_agent'] ?? ''),
            'ip' => (string) ($row['ip'] ?? ''),
            'request_id' => $row['request_id'] !== null ? (string) $row['request_id'] : null,
            'created_at' => (string) ($row['created_at'] ?? ''),
            'updated_at' => (string) ($row['updated_at'] ?? ''),
            'triaged_at' => $row['triaged_at'] !== null ? (string) $row['triaged_at'] : null,
            'ignored_at' => $row['ignored_at'] !== null ? (string) $row['ignored_at'] : null,
            'severity' => $this->severityTokens($row)['severity'],
        ];
    }

    /** @return array{severity: string, badge: string} */
    private function severityTokens(array $row): array
    {
        $directive = strtolower((string) ($row['violated_directive'] ?? ''));
        $type = strtolower((string) ($row['type'] ?? ''));

        $high = ['script-src', 'object-src', 'base-uri', 'frame-ancestors', 'trusted-types', 'require-trusted-types-for'];
        foreach ($high as $needle) {
            if ($directive !== '' && str_contains($directive, $needle)) {
                return ['severity' => 'high', 'badge' => 'danger'];
            }
        }

        $medium = ['style-src', 'connect-src', 'img-src', 'font-src', 'frame-src', 'child-src', 'worker-src', 'manifest-src'];
        foreach ($medium as $needle) {
            if ($directive !== '' && str_contains($directive, $needle)) {
                return ['severity' => 'medium', 'badge' => 'warning'];
            }
        }

        if ($type !== '' && $type !== 'csp') {
            return ['severity' => 'medium', 'badge' => 'warning'];
        }

        return ['severity' => 'low', 'badge' => 'success'];
    }

    private function statusLabel(string $status): string
    {
        return $this->view->translate(match ($status) {
            'triaged' => 'admin.security_reports.status.triaged',
            'ignored' => 'admin.security_reports.status.ignored',
            default => 'admin.security_reports.status.new',
        });
    }

    private function emptyDash(mixed $value): string
    {
        if (!is_string($value)) {
            return '-';
        }
        $value = trim($value);
        return $value !== '' ? $value : '-';
    }

    private function withSuccessTrigger(Response $response, string $messageKey): Response
    {
        return $response->withToastSuccess($messageKey, $this->view->translate($messageKey));
    }

    private function logAudit(Request $request, int $reportId, string $action): void
    {
        $actorId = $this->currentUserId($request);
        $actorUsername = $this->resolveUsername($actorId);

        Audit::log('security_report.' . $action, 'security_report', $reportId, [
            'report_id' => $reportId,
            'action' => $action,
            'actor_user_id' => $actorId,
            'actor_username' => $actorUsername,
        ]);
    }

    private function resolveUsername(?int $userId): ?string
    {
        if ($userId === null || $userId <= 0) {
            return null;
        }

        $users = $this->usersService();
        if ($users === null) {
            return null;
        }

        $user = $users->find($userId);
        $name = is_array($user) ? (string) ($user['username'] ?? '') : '';
        return $name !== '' ? $name : null;
    }

    private function forbidden(Request $request, string $route): Response
    {
        if ($request->wantsJson()) {
            return ContractResponse::error('forbidden', [
                'route' => $route,
            ], 403);
        }

        return ErrorResponse::respondForRequest($request, 'forbidden', [], 403, [], $route);
    }

    private function notFound(Request $request, string $route): Response
    {
        if ($request->wantsJson()) {
            return ContractResponse::error('not_found', [
                'route' => $route,
            ], 404);
        }

        return ErrorResponse::respondForRequest($request, 'not_found', [], 404, [], $route);
    }

    private function errorResponse(Request $request, string $code, int $status, string $route): Response
    {
        if ($request->wantsJson()) {
            return ContractResponse::error($code, [
                'route' => $route,
            ], $status);
        }

        return ErrorResponse::respondForRequest($request, $code, [], $status, [], $route);
    }
}
