<?php
declare(strict_types=1);

namespace Laas\Modules\Admin\Controller;

use Laas\Database\DatabaseManager;
use Laas\Database\Repositories\ModulesRepository;
use Laas\Database\Repositories\RbacRepository;
use Laas\Http\Contract\ContractResponse;
use Laas\Http\ErrorResponse;
use Laas\Http\Request;
use Laas\Http\Response;
use Laas\Modules\ModuleCatalog;
use Laas\Security\RateLimiter;
use Laas\Support\Audit;
use Laas\View\View;
use Throwable;

final class ModulesController
{
    public function __construct(
        private View $view,
        private ?DatabaseManager $db = null
    ) {
    }

    public function index(Request $request): Response
    {
        if (!$this->canManage($request)) {
            return $this->forbidden($request, 'admin.modules.index');
        }

        $catalog = new ModuleCatalog(dirname(__DIR__, 3), $this->db);
        $modules = $catalog->listAll();
        $filters = $this->normalizeFilters($request);
        $modules = $this->filterModules($modules, $filters);

        if ($request->wantsJson()) {
            $items = $this->jsonItems($modules);
            $enabledCount = 0;
            foreach ($items as $item) {
                if (!empty($item['enabled'])) {
                    $enabledCount++;
                }
            }

            return ContractResponse::ok([
                'items' => $items,
                'counts' => [
                    'total' => count($items),
                    'enabled' => $enabledCount,
                ],
            ], [
                'route' => 'admin.modules.index',
            ]);
        }

        return $this->view->render('pages/modules.html', [
            'modules' => $modules,
            'filters' => $filters,
            'filter_status_all' => $filters['status'] === 'all',
            'filter_status_on' => $filters['status'] === 'on',
            'filter_status_off' => $filters['status'] === 'off',
            'filter_type_all' => $filters['type'] === 'all',
            'filter_type_admin' => $filters['type'] === 'admin',
            'filter_type_general' => $filters['type'] === 'general',
            'filter_type_internal' => $filters['type'] === 'internal',
            'filter_type_api' => $filters['type'] === 'api',
        ], 200, [], [
            'theme' => 'admin',
        ]);
    }

    public function details(Request $request): Response
    {
        if (!$this->canManage($request)) {
            return $this->forbidden($request, 'admin.modules.index');
        }

        $rateLimited = $this->rateLimitDetails($request);
        if ($rateLimited !== null) {
            return $rateLimited;
        }

        $moduleId = trim((string) ($request->query('module') ?? ''));
        if ($moduleId === '' || !preg_match('/^[a-z0-9\\-]+$/', $moduleId)) {
            return $this->renderDetailsError('Invalid module id.', 400);
        }

        $catalog = new ModuleCatalog(dirname(__DIR__, 3), $this->db);
        $modules = $catalog->listAll();
        $module = null;
        foreach ($modules as $item) {
            if (is_array($item) && ($item['module_id'] ?? null) === $moduleId) {
                $module = $item;
                break;
            }
        }

        if ($module === null) {
            return $this->renderDetailsError('Module not found.', 404);
        }

        $close = (string) ($request->query('close') ?? '');
        if ($close !== '') {
            return new Response('', 200, [
                ...$this->detailsHeaders(),
            ]);
        }

        return $this->view->render('partials/module_details.html', [
            'module' => $module,
        ], 200, $this->detailsHeaders(), [
            'theme' => 'admin',
            'render_partial' => true,
        ]);
    }

    public function toggle(Request $request): Response
    {
        if (!$this->canManage($request)) {
            return $this->forbidden($request, 'admin.modules.toggle');
        }

        $name = $request->post('name') ?? '';
        if ($name === '') {
            return $this->errorResponse($request, 'invalid_request', 400, 'admin.modules.toggle');
        }

        $discovered = $this->discoverModules();
        $type = $discovered[$name]['type'] ?? 'feature';
        if ($type !== 'feature') {
            return $this->errorResponse($request, 'protected_module', 400, 'admin.modules.toggle');
        }

        if ($this->db === null || !$this->db->healthCheck()) {
            return $this->errorResponse($request, 'db_unavailable', 503, 'admin.modules.toggle');
        }

        $repo = new ModulesRepository($this->db->pdo());
        $current = $repo->all();
        $enabled = !empty($current[$name]['enabled']);
        if ($enabled) {
            $repo->disable($name);
        } else {
            $repo->enable($name);
        }

        Audit::log('modules.toggle', 'module', null, [
            'actor_user_id' => $this->currentUserId($request),
            'module' => $name,
            'from_enabled' => $enabled,
            'to_enabled' => !$enabled,
        ]);

        $row = $current[$name] ?? ['enabled' => !$enabled, 'version' => null];
        $typeLabel = $this->typeLabel($type);
        $protected = $type !== 'feature';
        $module = [
            'name' => $name,
            'enabled' => !$enabled,
            'version' => $discovered[$name]['version'] ?? null,
            'type' => $type,
            'type_label' => $typeLabel,
            'type_is_internal' => $type === 'internal',
            'type_is_admin' => $type === 'admin',
            'type_is_api' => $type === 'api',
            'source' => 'DB',
            'protected' => $protected,
        ];

        if ($request->wantsJson()) {
            return ContractResponse::ok([
                'name' => $name,
                'enabled' => !$enabled,
                'protected' => $protected,
            ], [
                'status' => 'ok',
                'route' => 'admin.modules.toggle',
            ]);
        }

        if ($request->isHtmx()) {
            $response = $this->view->render('partials/module_row.html', [
                'module' => $module,
            ], 200, [], [
                'theme' => 'admin',
            ]);
            $messageKey = $enabled ? 'admin.modules.disable' : 'admin.modules.enable';
            return $this->withSuccessTrigger($response, $messageKey);
        }

        return new Response('', 302, [
            'Location' => '/admin/modules',
        ]);
    }

    /** @return array<string, array{path: string, version: string|null, type: string}> */
    private function discoverModules(): array
    {
        $modulesDir = dirname(__DIR__, 3) . '/modules';
        $modulesDir = realpath($modulesDir) ?: $modulesDir;
        if (!is_dir($modulesDir)) {
            return [];
        }

        $items = scandir($modulesDir) ?: [];
        $discovered = [];
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $modulesDir . '/' . $item;
            if (!is_dir($path)) {
                continue;
            }

            $name = $item;
            $version = null;
            $type = 'feature';
            $metaPath = $path . '/module.json';
            if (is_file($metaPath)) {
                $raw = (string) file_get_contents($metaPath);
                $data = json_decode($raw, true);
                if (is_array($data)) {
                    $name = is_string($data['name'] ?? null) ? $data['name'] : $name;
                    $version = is_string($data['version'] ?? null) ? $data['version'] : null;
                    $type = is_string($data['type'] ?? null) ? $data['type'] : $type;
                }
            }

            $discovered[$name] = [
                'path' => $path,
                'version' => $version,
                'type' => $type,
            ];
        }

        if (!isset($discovered['Audit'])) {
            $adminVersion = $discovered['Admin']['version'] ?? null;
            $discovered['Audit'] = [
                'path' => '',
                'version' => $adminVersion,
                'type' => 'internal',
            ];
        }

        return $discovered;
    }

    /** @return string[] */
    private function loadConfigEnabled(): array
    {
        $configPath = dirname(__DIR__, 3) . '/config/modules.php';
        $configPath = realpath($configPath) ?: $configPath;
        if (!is_file($configPath)) {
            return [];
        }

        $config = require $configPath;
        if (!is_array($config)) {
            return [];
        }

        if (isset($config['enabled']) && is_array($config['enabled'])) {
            return array_values(array_filter($config['enabled'], 'is_string'));
        }

        $names = [];
        foreach ($config as $class) {
            if (!is_string($class)) {
                continue;
            }
            $parts = explode('\\', trim($class, '\\'));
            $name = $parts[2] ?? '';
            if ($name !== '') {
                $names[] = $name;
            }
        }

        return $names;
    }

    private function errorResponse(Request $request, string $code, int $status, string $route): Response
    {
        if ($request->wantsJson()) {
            return ContractResponse::error($code, ['route' => $route], $status);
        }

        return ErrorResponse::respondForRequest($request, $code, [], $status, [], $route);
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

    private function canManage(Request $request): bool
    {
        return $this->hasPermission($request, 'admin.modules.manage');
    }

    private function hasPermission(Request $request, string $permission): bool
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
            return $rbac->userHasPermission($userId, $permission);
        } catch (Throwable) {
            return false;
        }
    }

    private function forbidden(Request $request, string $route): Response
    {
        if ($request->wantsJson()) {
            return ContractResponse::error('forbidden', ['route' => $route], 403);
        }

        return ErrorResponse::respondForRequest($request, 'forbidden', [], 403, [], $route);
    }

    private function typeLabel(string $type): string
    {
        return match ($type) {
            'internal' => $this->view->translate('admin.modules.type_internal'),
            'admin' => $this->view->translate('admin.modules.type_admin'),
            'api' => $this->view->translate('admin.modules.type_api'),
            default => $this->view->translate('admin.modules.type_feature'),
        };
    }

    /**
     * @return array{q: string, status: string, type: string}
     */
    private function normalizeFilters(Request $request): array
    {
        $q = trim((string) ($request->query('q') ?? ''));
        $status = strtolower(trim((string) ($request->query('status') ?? 'all')));
        if (!in_array($status, ['all', 'on', 'off'], true)) {
            $status = 'all';
        }
        $type = strtolower(trim((string) ($request->query('type') ?? 'all')));
        if (!in_array($type, ['all', 'admin', 'general', 'internal', 'api'], true)) {
            $type = 'all';
        }

        return [
            'q' => $q,
            'status' => $status,
            'type' => $type,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $modules
     * @param array{q: string, status: string, type: string} $filters
     * @return array<int, array<string, mixed>>
     */
    private function filterModules(array $modules, array $filters): array
    {
        $q = strtolower($filters['q']);
        $status = $filters['status'];
        $type = $filters['type'];

        $out = [];
        foreach ($modules as $module) {
            if (!is_array($module)) {
                continue;
            }

            if ($status !== 'all') {
                $enabled = (bool) ($module['enabled'] ?? false);
                if ($status === 'on' && !$enabled) {
                    continue;
                }
                if ($status === 'off' && $enabled) {
                    continue;
                }
            }

            if ($type !== 'all') {
                $wanted = $type === 'general' ? 'feature' : $type;
                $moduleType = (string) ($module['type'] ?? '');
                if ($moduleType !== $wanted) {
                    continue;
                }
            }

            if ($q !== '') {
                $haystack = strtolower(
                    (string) ($module['name'] ?? '')
                    . ' '
                    . (string) ($module['notes'] ?? '')
                    . ' '
                    . (string) ($module['type'] ?? '')
                );
                if (!str_contains($haystack, $q)) {
                    continue;
                }
            }

            $out[] = $module;
        }

        return $out;
    }

    private function renderDetailsError(string $message, int $status): Response
    {
        return $this->view->render('partials/module_details.html', [
            'error' => $message,
        ], $status, $this->detailsHeaders(), [
            'theme' => 'admin',
            'render_partial' => true,
        ]);
    }

    private function rateLimitDetails(Request $request): ?Response
    {
        $ip = $request->ip();
        if ($ip === '') {
            return null;
        }

        try {
            $limiter = new RateLimiter(dirname(__DIR__, 3));
            $result = $limiter->hit('admin.modules.details', $ip, 60, 60);
            if (!$result['allowed']) {
                return $this->renderDetailsError('Too many requests.', 429);
            }
        } catch (Throwable) {
            return null;
        }

        return null;
    }

    /**
     * @return array<string, string>
     */
    private function detailsHeaders(): array
    {
        return [
            'Content-Type' => 'text/html; charset=utf-8',
            'Cache-Control' => 'no-store',
        ];
    }

    private function withSuccessTrigger(Response $response, string $messageKey): Response
    {
        return $response->withToastSuccess($messageKey, $this->view->translate($messageKey));
    }

    /** @return array<int, array{name: string, enabled: bool, version: string|null, type: string, protected: bool}> */
    private function jsonItems(array $modules): array
    {
        $items = [];
        foreach ($modules as $module) {
            $type = (string) ($module['type'] ?? 'feature');
            $items[] = [
                'name' => (string) ($module['name'] ?? ''),
                'enabled' => (bool) ($module['enabled'] ?? false),
                'version' => is_string($module['version'] ?? null) ? $module['version'] : null,
                'type' => $this->jsonType($type),
                'protected' => (bool) ($module['protected'] ?? $type !== 'feature'),
            ];
        }

        return $items;
    }

    private function jsonType(string $type): string
    {
        return match ($type) {
            'internal' => 'internal',
            'admin', 'api' => 'core',
            default => 'module',
        };
    }
}
