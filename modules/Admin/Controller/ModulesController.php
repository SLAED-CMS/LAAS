<?php
declare(strict_types=1);

namespace Laas\Modules\Admin\Controller;

use Laas\Database\DatabaseManager;
use Laas\Database\Repositories\ModulesRepository;
use Laas\Database\Repositories\RbacRepository;
use Laas\Http\Contract\ContractResponse;
use Laas\Http\ErrorResponse;
use Laas\Http\HtmxTrigger;
use Laas\Http\Request;
use Laas\Http\RequestContext;
use Laas\Http\Response;
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

        $modules = [];
        $discovered = $this->discoverModules();
        $configEnabled = $this->loadConfigEnabled();
        $dbModules = null;
        $dbAvailable = $this->db !== null && $this->db->healthCheck();
        if ($dbAvailable) {
            $repo = new ModulesRepository($this->db->pdo());
            $dbModules = $repo->all();
        }

        if ($discovered === []) {
            foreach ($configEnabled as $name) {
                $discovered[$name] = [
                    'path' => '',
                    'version' => null,
                    'type' => 'feature',
                ];
            }
        }

        foreach ($discovered as $name => $meta) {
            $enabled = false;
            $source = 'CONFIG';
            if ($dbAvailable && is_array($dbModules) && array_key_exists($name, $dbModules)) {
                $enabled = (bool) ($dbModules[$name]['enabled'] ?? false);
                $source = 'DB';
            } elseif (in_array($name, $configEnabled, true)) {
                $enabled = true;
            }

            $type = (string) ($meta['type'] ?? 'feature');
            $typeLabel = $this->typeLabel($type);
            if ($type !== 'feature' && $enabled === false && !$dbAvailable && !in_array($name, $configEnabled, true)) {
                $enabled = true;
                $source = 'INTERNAL';
            }

            $modules[] = [
                'name' => $name,
                'enabled' => $enabled,
                'version' => $meta['version'] ?? null,
                'type' => $type,
                'type_label' => $typeLabel,
                'type_is_internal' => $type === 'internal',
                'type_is_admin' => $type === 'admin',
                'type_is_api' => $type === 'api',
                'source' => $source,
                'protected' => $type !== 'feature',
            ];
        }

        usort($modules, static fn(array $a, array $b): int => strcmp($a['name'], $b['name']));

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
        ], 200, [], [
            'theme' => 'admin',
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

    private function withSuccessTrigger(Response $response, string $messageKey): Response
    {
        return HtmxTrigger::add($response, 'laas:success', [
            'message_key' => $messageKey,
            'message' => $this->view->translate($messageKey),
            'request_id' => RequestContext::requestId(),
        ]);
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
