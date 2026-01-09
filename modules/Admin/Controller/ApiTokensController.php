<?php
declare(strict_types=1);

namespace Laas\Modules\Admin\Controller;

use Laas\Api\ApiTokenService;
use Laas\Database\DatabaseManager;
use Laas\Database\Repositories\ApiTokensRepository;
use Laas\Database\Repositories\RbacRepository;
use Laas\Http\Request;
use Laas\Http\Response;
use Laas\Support\AuditLogger;
use Laas\View\View;
use Throwable;

final class ApiTokensController
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

        $repo = $this->repository();
        if ($repo === null) {
            return $this->errorResponse($request, 'db_unavailable', 503);
        }

        $userId = $this->currentUserId($request);
        if ($userId === null) {
            return $this->forbidden($request);
        }

        $tokens = $this->mapTokens($repo->listByUser($userId, 100, 0));

        return $this->renderPage($request, $tokens, null, null, null, []);
    }

    public function create(Request $request): Response
    {
        if (!$this->canManage($request)) {
            return $this->forbidden($request);
        }

        $repo = $this->repository();
        if ($repo === null) {
            return $this->errorResponse($request, 'db_unavailable', 503);
        }

        $userId = $this->currentUserId($request);
        if ($userId === null) {
            return $this->forbidden($request);
        }

        $name = trim((string) ($request->post('name') ?? ''));
        $expiresRaw = trim((string) ($request->post('expires_at') ?? ''));

        $errors = [];
        if ($name === '' || strlen($name) > 100) {
            $errors[] = $this->view->translate('admin.settings.error_invalid');
        }

        $expiresAt = null;
        if ($expiresRaw !== '') {
            $expiresAt = $this->parseExpiresAt($expiresRaw);
            if ($expiresAt === null) {
                $errors[] = $this->view->translate('admin.settings.error_invalid');
            }
        }

        if ($errors !== []) {
            $tokens = $this->mapTokens($repo->listByUser($userId, 100, 0));
            return $this->renderPage($request, $tokens, null, null, null, $errors, 422);
        }

        $service = new ApiTokenService($this->db);
        $created = $service->issueToken($userId, $name, $expiresAt);

        (new AuditLogger($this->db, $request->session()))->log(
            'api.token.created',
            'api_token',
            (int) ($created['token_id'] ?? 0),
            [
                'name' => $name,
                'expires_at' => $expiresAt,
            ],
            $userId,
            $request->ip()
        );

        $tokens = $this->mapTokens($repo->listByUser($userId, 100, 0));
        $plain = (string) ($created['token'] ?? '');

        $success = $this->view->translate('admin.api_tokens.created');
        return $this->renderPage($request, $tokens, $plain, $name, $success, [], 201);
    }

    public function rotate(Request $request): Response
    {
        if (!$this->canManage($request)) {
            return $this->forbidden($request);
        }

        $repo = $this->repository();
        if ($repo === null) {
            return $this->errorResponse($request, 'db_unavailable', 503);
        }

        $userId = $this->currentUserId($request);
        if ($userId === null) {
            return $this->forbidden($request);
        }

        $id = $this->readId($request);
        if ($id === null) {
            return $this->errorResponse($request, 'invalid_request', 400);
        }

        $existing = $repo->findById($id);
        if ($existing === null || (int) ($existing['user_id'] ?? 0) !== $userId) {
            return $this->errorResponse($request, 'not_found', 404);
        }

        $nameRaw = trim((string) ($request->post('name') ?? ''));
        $name = $nameRaw !== '' ? $nameRaw : $this->rotateName((string) ($existing['name'] ?? ''));
        $expiresRaw = trim((string) ($request->post('expires_at') ?? ''));
        $revokeOld = (string) ($request->post('revoke_old') ?? '') === '1';

        $errors = [];
        if ($name === '' || strlen($name) > 100) {
            $errors[] = $this->view->translate('admin.settings.error_invalid');
        }

        $expiresAt = null;
        if ($expiresRaw !== '') {
            $expiresAt = $this->parseExpiresAt($expiresRaw);
            if ($expiresAt === null) {
                $errors[] = $this->view->translate('admin.settings.error_invalid');
            }
        }

        if ($errors !== []) {
            $tokens = $this->mapTokens($repo->listByUser($userId, 100, 0));
            return $this->renderPage($request, $tokens, null, null, null, $errors, 422);
        }

        $service = new ApiTokenService($this->db);
        $created = $service->issueToken($userId, $name, $expiresAt);

        (new AuditLogger($this->db, $request->session()))->log(
            'api.token.created',
            'api_token',
            (int) ($created['token_id'] ?? 0),
            [
                'name' => $name,
                'expires_at' => $expiresAt,
                'rotated_from' => $id,
            ],
            $userId,
            $request->ip()
        );

        if ($revokeOld && $repo->revoke($id, $userId)) {
            (new AuditLogger($this->db, $request->session()))->log(
                'api.token.revoked',
                'api_token',
                $id,
                [
                    'token_id' => $id,
                    'user_id' => $userId,
                    'rotated_to' => (int) ($created['token_id'] ?? 0),
                ],
                $userId,
                $request->ip()
            );
        }

        $tokens = $this->mapTokens($repo->listByUser($userId, 100, 0));
        $plain = (string) ($created['token'] ?? '');

        $success = $this->view->translate('admin.api_tokens.rotated');
        return $this->renderPage($request, $tokens, $plain, $name, $success, [], 201);
    }

    public function revoke(Request $request): Response
    {
        if (!$this->canManage($request)) {
            return $this->forbidden($request);
        }

        $repo = $this->repository();
        if ($repo === null) {
            return $this->errorResponse($request, 'db_unavailable', 503);
        }

        $userId = $this->currentUserId($request);
        if ($userId === null) {
            return $this->forbidden($request);
        }

        $id = $this->readId($request);
        if ($id === null) {
            return $this->errorResponse($request, 'invalid_request', 400);
        }

        $ok = $repo->revoke($id, $userId);
        if ($ok) {
            (new AuditLogger($this->db, $request->session()))->log(
                'api.token.revoked',
                'api_token',
                $id,
                [
                    'token_id' => $id,
                    'user_id' => $userId,
                ],
                $userId,
                $request->ip()
            );
        }

        $tokens = $this->mapTokens($repo->listByUser($userId, 100, 0));
        $success = $ok ? $this->view->translate('admin.api_tokens.revoked') : null;

        return $this->renderPage($request, $tokens, null, null, $success, [], 200);
    }

    private function renderPage(
        Request $request,
        array $tokens,
        ?string $plainToken,
        ?string $tokenName,
        ?string $success,
        array $errors,
        int $status = 200
    ): Response {
        $viewData = [
            'tokens' => $tokens,
            'plain_token' => $plainToken,
            'token_name' => $tokenName,
            'success' => $success,
            'errors' => $errors,
        ];

        if ($request->isHtmx()) {
            return $this->view->render('partials/api_tokens_response.html', $viewData, $status, [], [
                'theme' => 'admin',
                'render_partial' => true,
            ]);
        }

        return $this->view->render('pages/api_tokens.html', $viewData, $status, [], [
            'theme' => 'admin',
        ]);
    }

    private function repository(): ?ApiTokensRepository
    {
        if ($this->db === null || !$this->db->healthCheck()) {
            return null;
        }

        try {
            return new ApiTokensRepository($this->db->pdo());
        } catch (Throwable) {
            return null;
        }
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
            return $rbac->userHasPermission($userId, 'api.tokens.manage');
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

    private function errorResponse(Request $request, string $code, int $status): Response
    {
        if ($request->isHtmx() || $request->wantsJson()) {
            return Response::json(['error' => $code], $status);
        }

        return new Response('Error', $status, [
            'Content-Type' => 'text/plain; charset=utf-8',
        ]);
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

    private function mapTokens(array $rows): array
    {
        $items = [];
        foreach ($rows as $row) {
            $lastUsed = (string) ($row['last_used_at'] ?? '');
            $expiresAt = (string) ($row['expires_at'] ?? '');
            $revokedAt = (string) ($row['revoked_at'] ?? '');
            $status = $this->status($expiresAt, $revokedAt);
            $statusLabel = $this->statusLabel($status);
            $isActive = $status === 'active';
            $isExpired = $status === 'expired';
            $isRevoked = $status === 'revoked';
            $items[] = [
                'id' => (int) ($row['id'] ?? 0),
                'name' => (string) ($row['name'] ?? ''),
                'last_used_at' => $lastUsed !== '' ? $lastUsed : '-',
                'expires_at' => $expiresAt !== '' ? $expiresAt : '-',
                'expires_at_raw' => $expiresAt,
                'revoked_at' => $revokedAt !== '' ? $revokedAt : '-',
                'created_at' => (string) ($row['created_at'] ?? ''),
                'status' => $status,
                'status_label' => $statusLabel,
                'is_active' => $isActive,
                'is_expired' => $isExpired,
                'is_revoked' => $isRevoked,
                'revoke_allowed' => $isActive,
            ];
        }

        return $items;
    }

    private function parseExpiresAt(string $value): ?string
    {
        $ts = strtotime($value);
        if ($ts === false) {
            return null;
        }

        return date('Y-m-d H:i:s', $ts);
    }

    private function status(string $expiresAt, string $revokedAt): string
    {
        if ($revokedAt !== '') {
            return 'revoked';
        }
        if ($expiresAt !== '') {
            $ts = strtotime($expiresAt);
            if ($ts !== false && $ts < time()) {
                return 'expired';
            }
        }

        return 'active';
    }

    private function rotateName(string $name): string
    {
        if ($name === '') {
            return 'Rotated token';
        }

        return $name . ' (rotated)';
    }

    private function statusLabel(string $status): string
    {
        return $this->view->translate(match ($status) {
            'revoked' => 'admin.api_tokens.status.revoked',
            'expired' => 'admin.api_tokens.status.expired',
            default => 'admin.api_tokens.status.active',
        });
    }

}
