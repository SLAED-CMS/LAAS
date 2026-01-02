<?php
declare(strict_types=1);

namespace Laas\Modules\Admin\Controller;

use Laas\Database\DatabaseManager;
use Laas\Database\Repositories\AuditLogRepository;
use Laas\Database\Repositories\RbacRepository;
use Laas\Http\Request;
use Laas\Http\Response;
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
        if (!$this->canView()) {
            return $this->forbidden($request);
        }

        if ($this->db === null || !$this->db->healthCheck()) {
            return $this->errorResponse($request, 'db_unavailable', 503);
        }

        try {
            $repo = new AuditLogRepository($this->db);
            $page = $this->readPage($request);
            $limit = 50;
            $offset = ($page - 1) * $limit;
            $total = $repo->countAll();
            $rows = $repo->list($limit, $offset);
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
                'entity_id' => $row['entity_id'] ?? null,
                'context_preview' => $contextPreview !== '' ? $contextPreview : '-',
            ];
        }, $rows);

        $totalPages = max(1, (int) ceil($total / $limit));
        $page = min($page, $totalPages);
        $hasPrev = $page > 1;
        $hasNext = $page < $totalPages;
        $prevUrl = $hasPrev ? '/admin/audit?page=' . ($page - 1) : '#';
        $nextUrl = $hasNext ? '/admin/audit?page=' . ($page + 1) : '#';

        return $this->view->render('pages/audit.html', [
            'logs' => $logs,
            'pagination' => [
                'page' => $page,
                'total_pages' => $totalPages,
                'has_prev' => $hasPrev,
                'has_next' => $hasNext,
                'prev_url' => $prevUrl,
                'next_url' => $nextUrl,
                'prev_disabled_class' => $hasPrev ? '' : 'disabled',
                'next_disabled_class' => $hasNext ? '' : 'disabled',
            ],
        ], 200, [], [
            'theme' => 'admin',
        ]);
    }

    private function canView(): bool
    {
        if ($this->db === null || !$this->db->healthCheck()) {
            return false;
        }

        $userId = $this->currentUserId();
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

    private function forbidden(Request $request): Response
    {
        if ($request->wantsJson()) {
            return Response::json(['error' => 'forbidden'], 403);
        }

        return $this->view->render('pages/403.html', [], 403, [], [
            'theme' => 'admin',
        ]);
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
