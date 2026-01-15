<?php
declare(strict_types=1);

namespace Laas\Modules\Admin\Controller;

use Laas\Database\DatabaseManager;
use Laas\Database\Repositories\AuditLogRepository;
use Laas\Database\Repositories\RbacRepository;
use Laas\Http\ErrorResponse;
use Laas\Http\Request;
use Laas\Http\Response;
use Laas\View\SanitizedHtml;
use Laas\View\View;
use Throwable;

final class AuditController
{
    public function __construct(
        private View $view,
        private ?DatabaseManager $db = null
    ) {
    }

    public function index(Request $request): Response
    {
        if (!$this->canView($request)) {
            return $this->forbidden($request);
        }

        if ($this->db === null || !$this->db->healthCheck()) {
            return $this->errorResponse($request, 'db_unavailable', 503);
        }

        try {
            $repo = new AuditLogRepository($this->db);
            $filters = $this->readFilters($request);
            if (!$filters['valid']) {
                $message = $this->view->translate('audit.filters.invalid_range');
                if ($request->isHtmx()) {
                    $response = $this->view->render('partials/messages.html', [
                        'errors' => [$message],
                    ], 422, [], [
                        'theme' => 'admin',
                        'render_partial' => true,
                    ]);
                    return $response->withHeader('HX-Retarget', '#page-messages');
                }

                $actions = $repo->listActions();
                $actionOptions = array_map(static function (string $action) use ($filters): array {
                    return [
                        'name' => $action,
                        'selected' => SanitizedHtml::fromSanitized($action === $filters['values']['action'] ? 'selected' : ''),
                    ];
                }, $actions);

                return $this->view->render('pages/audit.html', [
                    'logs' => [],
                    'filters' => $filters['values'],
                    'actions' => $actionOptions,
                    'users' => $repo->listUsers(),
                    'pagination' => $this->emptyPagination(),
                    'errors' => [$message],
                ], 422, [], [
                    'theme' => 'admin',
                ]);
            }

            $page = $this->readPage($request);
            $limit = 50;
            $offset = ($page - 1) * $limit;
            $total = $repo->countSearch($filters['values']);
            $rows = $repo->search($filters['values'], $limit, $offset);
            $actions = $repo->listActions();
            $users = $repo->listUsers();
            $actionOptions = array_map(static function (string $action) use ($filters): array {
                return [
                    'name' => $action,
                    'selected' => SanitizedHtml::fromSanitized($action === $filters['values']['action'] ? 'selected' : ''),
                ];
            }, $actions);
        } catch (Throwable) {
            return $this->errorResponse($request, 'db_unavailable', 503);
        }

        $logs = array_map(function (array $row): array {
            $contextPreview = $this->summarizeContext($row['context'] ?? null);
            return [
                'id' => (int) ($row['id'] ?? 0),
                'created_at' => (string) ($row['created_at'] ?? ''),
                'user' => (string) ($row['username'] ?? ''),
                'user_id' => $row['user_id'] ?? null,
                'action' => (string) ($row['action'] ?? ''),
                'entity' => (string) ($row['entity'] ?? ''),
                'entity_id' => SanitizedHtml::fromSanitized((string) ($row['entity_id'] ?? '')),
                'context_preview' => $contextPreview !== '' ? $contextPreview : '-',
            ];
        }, $rows);

        $totalPages = max(1, (int) ceil($total / $limit));
        $page = min($page, $totalPages);
        $hasPrev = $page > 1;
        $hasNext = $page < $totalPages;
        $query = $this->buildQueryString($filters['values']);
        $prevUrl = $hasPrev ? '/admin/audit?page=' . ($page - 1) . $query : '#';
        $nextUrl = $hasNext ? '/admin/audit?page=' . ($page + 1) . $query : '#';

        $viewData = [
            'logs' => $logs,
            'filters' => $filters['values'],
            'actions' => $actionOptions,
            'users' => $users,
            'pagination' => [
                'page' => SanitizedHtml::fromSanitized((string) $page),
                'total_pages' => SanitizedHtml::fromSanitized((string) $totalPages),
                'has_prev' => $hasPrev,
                'has_next' => $hasNext,
                'prev_url' => $prevUrl,
                'next_url' => $nextUrl,
            ],
        ];

        if ($request->isHtmx()) {
            return $this->view->render('partials/audit_table.html', $viewData, 200, [], [
                'theme' => 'admin',
                'render_partial' => true,
            ]);
        }

        return $this->view->render('pages/audit.html', $viewData, 200, [], [
            'theme' => 'admin',
        ]);
    }

    private function canView(Request $request): bool
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
            return $rbac->userHasPermission($userId, 'audit.view');
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
        return ErrorResponse::respondForRequest($request, 'forbidden', [], 403, [], 'admin.audit');
    }

    private function errorResponse(Request $request, string $code, int $status): Response
    {
        return ErrorResponse::respondForRequest($request, $code, [], $status, [], 'admin.audit');
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

    /** @return array{valid: bool, values: array{user: string, action: string, from: string, to: string}} */
    private function readFilters(Request $request): array
    {
        $user = trim((string) ($request->query('user') ?? ''));
        $action = trim((string) ($request->query('action') ?? ''));
        $from = trim((string) ($request->query('from') ?? ''));
        $to = trim((string) ($request->query('to') ?? ''));

        $fromOk = $from === '' ? true : $this->isDate($from);
        $toOk = $to === '' ? true : $this->isDate($to);
        if (!$fromOk || !$toOk) {
            return [
                'valid' => false,
                'values' => [
                    'user' => $user,
                    'action' => $action,
                    'from' => $from,
                    'to' => $to,
                ],
            ];
        }

        if ($from !== '' && $to !== '') {
            $fromTime = strtotime($from . ' 00:00:00');
            $toTime = strtotime($to . ' 23:59:59');
            if ($fromTime !== false && $toTime !== false && $fromTime > $toTime) {
                return [
                    'valid' => false,
                    'values' => [
                        'user' => $user,
                        'action' => $action,
                        'from' => $from,
                        'to' => $to,
                    ],
                ];
            }
        }

        return [
            'valid' => true,
            'values' => [
                'user' => $user,
                'action' => $action,
                'from' => $from,
                'to' => $to,
            ],
        ];
    }

    private function isDate(string $value): bool
    {
        $dt = \DateTime::createFromFormat('Y-m-d', $value);
        return $dt instanceof \DateTime && $dt->format('Y-m-d') === $value;
    }

    /** @param array{user: string, action: string, from: string, to: string} $filters */
    private function buildQueryString(array $filters): string
    {
        $params = [];
        foreach ($filters as $key => $value) {
            if ($value !== '') {
                $params[$key] = $value;
            }
        }
        if ($params === []) {
            return '';
        }

        return '&' . http_build_query($params);
    }

    private function emptyPagination(): array
    {
        return [
            'page' => 1,
            'total_pages' => 1,
            'has_prev' => false,
            'has_next' => false,
            'prev_url' => '#',
            'next_url' => '#',
        ];
    }

    private function summarizeContext(mixed $raw): string
    {
        if ($raw === null || $raw === '') {
            return '';
        }

        if (is_array($raw)) {
            $text = $this->flattenContext($raw);
            return $this->truncate($text, 140);
        }

        $decoded = json_decode((string) $raw, true);
        if (is_array($decoded)) {
            $text = $this->flattenContext($decoded);
            return $this->truncate($text, 140);
        }

        return $this->truncate((string) $raw, 140);
    }

    private function flattenContext(array $context): string
    {
        $parts = [];
        foreach ($context as $key => $value) {
            if (is_array($value)) {
                $value = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
            if (is_bool($value)) {
                $value = $value ? '1' : '0';
            }
            $parts[] = $key . '=' . (string) $value;
        }

        return implode(', ', $parts);
    }

    private function truncate(string $text, int $limit): string
    {
        if (function_exists('mb_strlen') && function_exists('mb_substr')) {
            if (mb_strlen($text, 'UTF-8') <= $limit) {
                return $text;
            }
            return mb_substr($text, 0, $limit, 'UTF-8') . '…';
        }

        if (strlen($text) <= $limit) {
            return $text;
        }

        return substr($text, 0, $limit) . '...';
    }
}
