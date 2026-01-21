<?php
declare(strict_types=1);

namespace Laas\Modules\Admin\Controller;

use Laas\Core\Container\Container;
use Laas\Domain\Rbac\RbacServiceInterface;
use Laas\Domain\ApiTokens\ApiTokensReadServiceInterface;
use Laas\Domain\ApiTokens\ApiTokensServiceException;
use Laas\Domain\ApiTokens\ApiTokensWriteServiceInterface;
use Laas\Http\Contract\ContractResponse;
use Laas\Http\ErrorResponse;
use Laas\Http\Request;
use Laas\Http\Response;
use Laas\Http\UiToast;
use Laas\Support\Audit;
use Laas\View\View;
use Throwable;

final class ApiTokensController
{
    public function __construct(
        private View $view,
        private ?ApiTokensReadServiceInterface $apiTokensReadService = null,
        private ?ApiTokensWriteServiceInterface $apiTokensWriteService = null,
        private ?Container $container = null
    ) {
    }

    public function index(Request $request): Response
    {
        if (!$this->canView($request)) {
            return $this->forbidden($request);
        }

        $userId = $this->currentUserId($request);
        if ($userId === null) {
            return $this->forbidden($request);
        }

        $readService = $this->readService();
        if ($readService === null) {
            return $this->errorResponse($request, 'db_unavailable', 503, 'admin.api_tokens.index');
        }

        try {
            $rows = $readService->listTokens($userId);
            $total = $readService->countTokens($userId);
        } catch (Throwable) {
            return $this->errorResponse($request, 'db_unavailable', 503, 'admin.api_tokens.index');
        }

        if ($request->wantsJson()) {
            return ContractResponse::ok([
                'items' => $this->mapTokensForJson($rows),
                'counts' => [
                    'total' => $total,
                ],
            ], [
                'route' => 'admin.api_tokens.index',
            ]);
        }

        $tokens = $this->mapTokensForView($rows);
        $selectedScopes = $readService->defaultScopesSelection();
        $scopeOptions = $this->buildScopeOptions($selectedScopes, $readService->allowedScopes());

        [$flashToken, $flashMessageKey] = $this->consumeFlashToken($request);
        $success = $flashMessageKey !== null ? $this->view->translate($flashMessageKey) : null;

        return $this->renderPage($request, $tokens, $flashToken, null, $scopeOptions, $success, []);
    }

    public function create(Request $request): Response
    {
        if (!$this->canCreate($request)) {
            return $this->forbidden($request);
        }

        $userId = $this->currentUserId($request);
        if ($userId === null) {
            return $this->forbidden($request);
        }

        $readService = $this->readService();
        $writeService = $this->writeService();
        if ($readService === null || $writeService === null) {
            return $this->errorResponse($request, 'db_unavailable', 503, 'admin.api_tokens.create');
        }

        $input = $this->readInput($request);
        $name = trim((string) ($input['name'] ?? ''));
        $expiresRaw = trim((string) ($input['expires_at'] ?? ''));
        $scopesInput = $this->readScopes($input);

        try {
            $created = $writeService->createToken($userId, $name, $scopesInput, $expiresRaw);
        } catch (ApiTokensServiceException $e) {
            $code = $e->errorCode();
            $details = $e->details();
            $fields = is_array($details['fields'] ?? null) ? $details['fields'] : [];

            if ($code === 'validation') {
                if ($request->wantsJson()) {
                    return ContractResponse::error('validation_failed', [
                        'route' => 'admin.api_tokens.create',
                    ], 422, $fields);
                }

                try {
                    $tokens = $this->mapTokensForView($readService->listTokens($userId));
                } catch (Throwable) {
                    return $this->errorResponse($request, 'db_unavailable', 503, 'admin.api_tokens.create');
                }
                $selectedScopes = $this->selectScopes($scopesInput, $readService);
                $scopeOptions = $this->buildScopeOptions($selectedScopes, $readService->allowedScopes());
                $errors = $this->validationErrors($fields);
                return $this->renderPage($request, $tokens, null, $name, $scopeOptions, null, $errors, 422);
            }

            if ($code === 'limit') {
                return $this->errorResponse($request, 'invalid_request', 400, 'admin.api_tokens.create');
            }

            return $this->errorResponse($request, 'not_found', 404, 'admin.api_tokens.create');
        } catch (Throwable) {
            return $this->errorResponse($request, 'db_unavailable', 503, 'admin.api_tokens.create');
        }

        $scopes = is_array($created['scopes'] ?? null) ? $created['scopes'] : [];
        $expiresAt = is_string($created['expires_at'] ?? null) ? $created['expires_at'] : null;

        Audit::log('api_tokens.create', 'api_token', (int) ($created['token_id'] ?? 0), [
            'actor_user_id' => $userId,
            'name' => $name,
            'expires_at' => $expiresAt,
            'scopes' => $scopes,
            'token_prefix' => (string) ($created['token_prefix'] ?? ''),
        ]);

        if ($request->wantsJson()) {
            UiToast::registerSuccess($this->view->translate('admin.api_tokens.created'), 'admin.api_tokens.created');
            return ContractResponse::ok([
                'token_id' => (int) ($created['token_id'] ?? 0),
                'name' => $name,
                'token_prefix' => (string) ($created['token_prefix'] ?? ''),
                'scopes' => $scopes,
                'expires_at' => $expiresAt,
                'token_once' => (string) ($created['token'] ?? ''),
            ], [
                'route' => 'admin.api_tokens.create',
            ], 201);
        }

        if ($request->isHtmx()) {
            $tokens = $this->mapTokensForView($readService->listTokens($userId));
            $plain = (string) ($created['token'] ?? '');
            $selectedScopes = $readService->defaultScopesSelection();
            $scopeOptions = $this->buildScopeOptions($selectedScopes, $readService->allowedScopes());
            $success = $this->view->translate('admin.api_tokens.created');
            $response = $this->renderPage($request, $tokens, $plain, $name, $scopeOptions, $success, [], 200);
            return $this->withSuccessTrigger($response, 'admin.api_tokens.created');
        }

        $this->storeFlashToken($request, (string) ($created['token'] ?? ''), 'admin.api_tokens.created');
        return new Response('', 303, [
            'Location' => '/admin/api-tokens',
        ]);
    }

    public function rotate(Request $request): Response
    {
        if (!$this->canCreate($request)) {
            return $this->forbidden($request);
        }

        $userId = $this->currentUserId($request);
        if ($userId === null) {
            return $this->forbidden($request);
        }

        $readService = $this->readService();
        $writeService = $this->writeService();
        if ($readService === null || $writeService === null) {
            return $this->errorResponse($request, 'db_unavailable', 503, 'admin.api_tokens.rotate');
        }

        $input = $this->readInput($request);
        $id = $this->readId($request, $input);
        if ($id === null) {
            return $this->errorResponse($request, 'invalid_request', 400, 'admin.api_tokens.rotate');
        }

        $nameRaw = trim((string) ($input['name'] ?? ''));
        $expiresRaw = trim((string) ($input['expires_at'] ?? ''));
        $revokeOld = (string) ($input['revoke_old'] ?? '') === '1';
        $scopesInput = $this->readScopes($input);

        try {
            $created = $writeService->rotateToken($userId, $id, $nameRaw, $scopesInput, $expiresRaw, $revokeOld);
        } catch (ApiTokensServiceException $e) {
            $code = $e->errorCode();
            $details = $e->details();
            $fields = is_array($details['fields'] ?? null) ? $details['fields'] : [];

            if ($code === 'validation') {
                if ($request->wantsJson()) {
                    return ContractResponse::error('validation_failed', [
                        'route' => 'admin.api_tokens.rotate',
                    ], 422, $fields);
                }

                try {
                    $tokens = $this->mapTokensForView($readService->listTokens($userId));
                } catch (Throwable) {
                    return $this->errorResponse($request, 'db_unavailable', 503, 'admin.api_tokens.rotate');
                }
                $selectedScopes = $this->selectScopes($scopesInput, $readService);
                $scopeOptions = $this->buildScopeOptions($selectedScopes, $readService->allowedScopes());
                $errors = $this->validationErrors($fields);
                return $this->renderPage($request, $tokens, null, $nameRaw, $scopeOptions, null, $errors, 422);
            }

            if ($code === 'limit') {
                return $this->errorResponse($request, 'invalid_request', 400, 'admin.api_tokens.rotate');
            }

            return $this->errorResponse($request, 'not_found', 404, 'admin.api_tokens.rotate');
        } catch (Throwable) {
            return $this->errorResponse($request, 'db_unavailable', 503, 'admin.api_tokens.rotate');
        }

        $name = (string) ($created['name'] ?? '');
        $scopes = is_array($created['scopes'] ?? null) ? $created['scopes'] : [];
        $expiresAt = is_string($created['expires_at'] ?? null) ? $created['expires_at'] : null;
        $revokedOld = (bool) ($created['revoked_old'] ?? false);

        Audit::log('api_tokens.create', 'api_token', (int) ($created['token_id'] ?? 0), [
            'actor_user_id' => $userId,
            'name' => $name,
            'expires_at' => $expiresAt,
            'scopes' => $scopes,
            'token_prefix' => (string) ($created['token_prefix'] ?? ''),
            'rotated_from' => $id,
        ]);

        if ($revokeOld && $revokedOld) {
            Audit::log('api_tokens.revoke', 'api_token', $id, [
                'actor_user_id' => $userId,
                'token_id' => $id,
                'user_id' => $userId,
                'rotated_to' => (int) ($created['token_id'] ?? 0),
            ]);
        }

        if ($request->wantsJson()) {
            UiToast::registerSuccess($this->view->translate('admin.api_tokens.rotated'), 'admin.api_tokens.rotated');
            return ContractResponse::ok([
                'token_id' => (int) ($created['token_id'] ?? 0),
                'name' => $name,
                'token_prefix' => (string) ($created['token_prefix'] ?? ''),
                'scopes' => $scopes,
                'expires_at' => $expiresAt,
                'token_once' => (string) ($created['token'] ?? ''),
            ], [
                'route' => 'admin.api_tokens.rotate',
            ], 201);
        }

        if ($request->isHtmx()) {
            $tokens = $this->mapTokensForView($readService->listTokens($userId));
            $plain = (string) ($created['token'] ?? '');
            $selectedScopes = $readService->defaultScopesSelection();
            $scopeOptions = $this->buildScopeOptions($selectedScopes, $readService->allowedScopes());
            $success = $this->view->translate('admin.api_tokens.rotated');
            $response = $this->renderPage($request, $tokens, $plain, $name, $scopeOptions, $success, [], 200);
            return $this->withSuccessTrigger($response, 'admin.api_tokens.rotated');
        }

        $this->storeFlashToken($request, (string) ($created['token'] ?? ''), 'admin.api_tokens.rotated');
        return new Response('', 303, [
            'Location' => '/admin/api-tokens',
        ]);
    }

    public function revoke(Request $request): Response
    {
        if (!$this->canRevoke($request)) {
            return $this->forbidden($request);
        }

        $userId = $this->currentUserId($request);
        if ($userId === null) {
            return $this->forbidden($request);
        }

        $readService = $this->readService();
        $writeService = $this->writeService();
        if ($readService === null || $writeService === null) {
            return $this->errorResponse($request, 'db_unavailable', 503, 'admin.api_tokens.revoke');
        }

        $input = $this->readInput($request);
        $id = $this->readId($request, $input);
        if ($id === null) {
            return $this->errorResponse($request, 'invalid_request', 400, 'admin.api_tokens.revoke');
        }

        $ok = true;
        try {
            $writeService->revokeToken($id, $userId);
        } catch (ApiTokensServiceException $e) {
            if ($e->errorCode() === 'not_found') {
                $ok = false;
            } else {
                return $this->errorResponse($request, 'invalid_request', 400, 'admin.api_tokens.revoke');
            }
        } catch (Throwable) {
            return $this->errorResponse($request, 'db_unavailable', 503, 'admin.api_tokens.revoke');
        }

        if ($ok) {
            Audit::log('api_tokens.revoke', 'api_token', $id, [
                'actor_user_id' => $userId,
                'token_id' => $id,
                'user_id' => $userId,
            ]);
        }

        if ($request->wantsJson()) {
            if (!$ok) {
                return ContractResponse::error('not_found', [
                    'route' => 'admin.api_tokens.revoke',
                ], 404);
            }

            UiToast::registerInfo($this->view->translate('admin.api_tokens.revoked_ok'), 'admin.api_tokens.revoked_ok');
            return ContractResponse::ok([
                'revoked' => true,
                'token_id' => $id,
            ], [
                'route' => 'admin.api_tokens.revoke',
            ]);
        }

        try {
            $tokens = $this->mapTokensForView($readService->listTokens($userId));
        } catch (Throwable) {
            return $this->errorResponse($request, 'db_unavailable', 503, 'admin.api_tokens.revoke');
        }
        $selectedScopes = $readService->defaultScopesSelection();
        $scopeOptions = $this->buildScopeOptions($selectedScopes, $readService->allowedScopes());
        $success = $ok ? $this->view->translate('admin.api_tokens.revoked_ok') : null;
        $response = $this->renderPage($request, $tokens, null, null, $scopeOptions, $success, [], 200);
        if ($request->isHtmx() && $ok) {
            return $this->withSuccessTrigger($response, 'admin.api_tokens.revoked_ok');
        }
        return $response;
    }

    private function renderPage(
        Request $request,
        array $tokens,
        ?string $plainToken,
        ?string $tokenName,
        array $scopeOptions,
        ?string $success,
        array $errors,
        int $status = 200
    ): Response {
        $viewData = [
            'tokens' => $tokens,
            'plain_token' => $plainToken,
            'token_name' => $tokenName,
            'scope_options' => $scopeOptions,
            'success' => $success,
            'errors' => $errors,
        ];
        if (!$request->isHtmx() && $success !== null && $success !== '') {
            $viewData['flash'] = [
                'success' => $success,
            ];
        }

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

    private function storeFlashToken(Request $request, string $plainToken, string $messageKey): void
    {
        if ($plainToken === '') {
            return;
        }
        $session = $request->session();
        if (!$session->isStarted()) {
            return;
        }

        $session->set('flash.api_tokens.plain', $plainToken);
        $session->set('flash.api_tokens.message_key', $messageKey);
    }

    /** @return array{0: string|null, 1: string|null} */
    private function consumeFlashToken(Request $request): array
    {
        $session = $request->session();
        if (!$session->isStarted()) {
            return [null, null];
        }

        $plain = $session->get('flash.api_tokens.plain');
        $key = $session->get('flash.api_tokens.message_key');
        if (!is_string($plain) || $plain === '') {
            $session->delete('flash.api_tokens.plain');
            $session->delete('flash.api_tokens.message_key');
            return [null, null];
        }

        $session->delete('flash.api_tokens.plain');
        $session->delete('flash.api_tokens.message_key');

        return [$plain, is_string($key) && $key !== '' ? $key : null];
    }

    private function withSuccessTrigger(Response $response, string $messageKey): Response
    {
        return $response->withToastSuccess($messageKey, $this->view->translate($messageKey));
    }

    private function readService(): ?ApiTokensReadServiceInterface
    {
        if ($this->apiTokensReadService !== null) {
            return $this->apiTokensReadService;
        }

        if ($this->container !== null) {
            try {
                $service = $this->container->get(ApiTokensReadServiceInterface::class);
                if ($service instanceof ApiTokensReadServiceInterface) {
                    $this->apiTokensReadService = $service;
                    return $this->apiTokensReadService;
                }
            } catch (Throwable) {
                return null;
            }
        }

        return null;
    }

    private function writeService(): ?ApiTokensWriteServiceInterface
    {
        if ($this->apiTokensWriteService !== null) {
            return $this->apiTokensWriteService;
        }

        if ($this->container !== null) {
            try {
                $service = $this->container->get(ApiTokensWriteServiceInterface::class);
                if ($service instanceof ApiTokensWriteServiceInterface) {
                    $this->apiTokensWriteService = $service;
                    return $this->apiTokensWriteService;
                }
            } catch (Throwable) {
                return null;
            }
        }

        return null;
    }

    private function rbac(): ?RbacServiceInterface
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

    /** @return array<int, string> */
    private function selectScopes(array $scopesInput, ApiTokensReadServiceInterface $service): array
    {
        $allowed = $service->allowedScopes();
        $selected = [];
        foreach ($scopesInput as $scope) {
            if (!is_string($scope)) {
                continue;
            }
            $scope = trim($scope);
            if ($scope !== '' && in_array($scope, $allowed, true)) {
                $selected[] = $scope;
            }
        }

        if ($selected === [] && $allowed !== []) {
            return $service->defaultScopesSelection();
        }

        return array_values(array_unique($selected));
    }

    /** @return array<int, string> */
    private function validationErrors(array $fields): array
    {
        if ($fields === []) {
            return [];
        }

        $message = $this->view->translate('admin.settings.error_invalid');
        $errors = [];
        foreach (array_keys($fields) as $key) {
            $errors[] = $message;
        }

        return $errors !== [] ? $errors : [$message];
    }

    /** @return array<int, array<string, mixed>> */
    private function buildScopeOptions(array $selectedScopes, array $allowedScopes): array
    {
        $options = [];
        foreach ($allowedScopes as $scope) {
            $id = 'scope-' . preg_replace('/[^a-z0-9_-]/i', '-', $scope);
            $options[] = [
                'value' => $scope,
                'label' => $scope,
                'id' => $id,
                'checked' => in_array($scope, $selectedScopes, true),
            ];
        }

        return $options;
    }

    /** @return array<int, string> */
    private function readScopes(array $input): array
    {
        $raw = $input['scopes'] ?? [];
        if (is_string($raw)) {
            $parts = array_map('trim', explode(',', $raw));
            return array_values(array_filter($parts, static fn(string $part): bool => $part !== ''));
        }
        if (!is_array($raw)) {
            return [];
        }

        $out = [];
        foreach ($raw as $item) {
            if (!is_string($item)) {
                continue;
            }
            $item = trim($item);
            if ($item !== '') {
                $out[] = $item;
            }
        }

        return array_values(array_unique($out));
    }

    /** @return array<string, mixed> */
    private function readInput(Request $request): array
    {
        $contentType = strtolower((string) ($request->getHeader('content-type') ?? ''));
        if (str_contains($contentType, 'application/json')) {
            $raw = trim($request->getBody());
            if ($raw === '') {
                return [];
            }
            $data = json_decode($raw, true);
            return is_array($data) ? $data : [];
        }

        return $request->getPost();
    }

    private function canView(Request $request): bool
    {
        return $this->canPermission($request, 'api_tokens.view');
    }

    private function canCreate(Request $request): bool
    {
        return $this->canPermission($request, 'api_tokens.create');
    }

    private function canRevoke(Request $request): bool
    {
        return $this->canPermission($request, 'api_tokens.revoke');
    }

    private function canPermission(Request $request, string $permission): bool
    {
        $userId = $this->currentUserId($request);
        if ($userId === null) {
            return false;
        }

        $rbac = $this->rbac();
        if ($rbac === null) {
            return false;
        }

        if ($rbac->userHasPermission($userId, $permission)) {
            return true;
        }

        return $rbac->userHasPermission($userId, 'api.tokens.manage');
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

    private function readId(Request $request, ?array $input = null): ?int
    {
        $raw = $input !== null ? ($input['id'] ?? null) : $request->post('id');
        if ($raw === null || $raw === '') {
            return null;
        }
        if (is_int($raw)) {
            return $raw > 0 ? $raw : null;
        }
        if (!ctype_digit($raw)) {
            return null;
        }

        $id = (int) $raw;
        return $id > 0 ? $id : null;
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

    private function forbidden(Request $request): Response
    {
        if ($request->wantsJson()) {
            return ContractResponse::error('forbidden', [
                'route' => \Laas\Http\HeadlessMode::resolveRoute($request),
            ], 403);
        }

        return ErrorResponse::respondForRequest($request, 'forbidden', [], 403, [], 'admin.api_tokens');
    }

    private function mapTokensForView(array $rows): array
    {
        $items = [];
        foreach ($rows as $row) {
            $scopes = $this->decodeScopes($row['scopes'] ?? null);
            $scopesLabel = $scopes !== [] ? implode(', ', $scopes) : '-';
            $tokenPrefix = (string) ($row['token_prefix'] ?? '');
            $lastUsed = (string) ($row['last_used_at'] ?? '');
            $expiresAt = (string) ($row['expires_at'] ?? '');
            $revokedAt = (string) ($row['revoked_at'] ?? '');
            $status = (string) ($row['status'] ?? $this->status($expiresAt, $revokedAt));
            $statusLabel = $this->statusLabel($status);
            $isActive = $status === 'active';
            $isExpired = $status === 'expired';
            $isRevoked = $status === 'revoked';
            $items[] = [
                'id' => (int) ($row['id'] ?? 0),
                'name' => (string) ($row['name'] ?? ''),
                'token_prefix' => $tokenPrefix !== '' ? $tokenPrefix : '-',
                'token_hint' => $tokenPrefix !== '' ? ('LAAS_' . $tokenPrefix . '.*') : '-',
                'scopes' => $scopes,
                'scopes_label' => $scopesLabel,
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

    private function mapTokensForJson(array $rows): array
    {
        $items = [];
        foreach ($rows as $row) {
            $expiresAt = (string) ($row['expires_at'] ?? '');
            $revokedAt = (string) ($row['revoked_at'] ?? '');
            $items[] = [
                'id' => (int) ($row['id'] ?? 0),
                'name' => (string) ($row['name'] ?? ''),
                'token_prefix' => (string) ($row['token_prefix'] ?? ''),
                'scopes' => $this->decodeScopes($row['scopes'] ?? null),
                'last_used_at' => $this->normalizeDate($row['last_used_at'] ?? null),
                'expires_at' => $this->normalizeDate($row['expires_at'] ?? null),
                'revoked_at' => $this->normalizeDate($row['revoked_at'] ?? null),
                'created_at' => (string) ($row['created_at'] ?? ''),
                'status' => (string) ($row['status'] ?? $this->status($expiresAt, $revokedAt)),
            ];
        }

        return $items;
    }

    /** @return array<int, string> */
    private function decodeScopes(mixed $raw): array
    {
        if (is_array($raw)) {
            return array_values(array_filter(array_map(static fn($item): string => (string) $item, $raw)));
        }
        if (!is_string($raw) || $raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }

        $out = [];
        foreach ($decoded as $item) {
            if (!is_string($item)) {
                continue;
            }
            $item = trim($item);
            if ($item !== '') {
                $out[] = $item;
            }
        }

        return array_values(array_unique($out));
    }

    private function normalizeDate(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);
        return $value !== '' ? $value : null;
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

    private function statusLabel(string $status): string
    {
        return $this->view->translate(match ($status) {
            'revoked' => 'admin.api_tokens.status.revoked',
            'expired' => 'admin.api_tokens.status.expired',
            default => 'admin.api_tokens.status.active',
        });
    }

}
