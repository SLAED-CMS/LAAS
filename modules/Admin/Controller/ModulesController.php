<?php
declare(strict_types=1);

namespace Laas\Modules\Admin\Controller;

use Laas\Database\DatabaseManager;
use Laas\Database\Repositories\ModulesRepository;
use Laas\Http\Request;
use Laas\Http\Response;
use Laas\View\View;

final class ModulesController
{
    public function __construct(
        private View $view,
        private ?DatabaseManager $db = null
    ) {
    }

    public function index(Request $request): Response
    {
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
            $typeBadgeClass = $this->typeBadgeClass($type);
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
                'type_badge_class' => $typeBadgeClass,
                'source' => $source,
                'protected' => $type !== 'feature',
            ];
        }

        usort($modules, static fn(array $a, array $b): int => strcmp($a['name'], $b['name']));

        return $this->view->render('pages/modules.html', [
            'modules' => $modules,
        ], 200, [], [
            'theme' => 'admin',
        ]);
    }

    public function toggle(Request $request): Response
    {
        $name = $request->post('name') ?? '';
        if ($name === '') {
            return $this->errorResponse($request, 'invalid_request', 400);
        }

        $discovered = $this->discoverModules();
        $type = $discovered[$name]['type'] ?? 'feature';
        if ($type !== 'feature') {
            return $this->errorResponse($request, 'not_toggleable', 400);
        }

        if ($this->db === null || !$this->db->healthCheck()) {
            return $this->errorResponse($request, 'db_unavailable', 503);
        }

        $repo = new ModulesRepository($this->db->pdo());
        $current = $repo->all();
        $enabled = !empty($current[$name]['enabled']);
        if ($enabled) {
            $repo->disable($name);
        } else {
            $repo->enable($name);
        }

        $row = $current[$name] ?? ['enabled' => !$enabled, 'version' => null];
        $typeLabel = $this->typeLabel($type);
        $typeBadgeClass = $this->typeBadgeClass($type);
        $module = [
            'name' => $name,
            'enabled' => !$enabled,
            'version' => $discovered[$name]['version'] ?? null,
            'type' => $type,
            'type_label' => $typeLabel,
            'type_badge_class' => $typeBadgeClass,
            'source' => 'DB',
            'protected' => $type !== 'feature',
        ];

        if ($request->isHtmx()) {
            return $this->view->render('partials/module_row.html', [
                'module' => $module,
            ], 200, [], [
                'theme' => 'admin',
            ]);
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

    private function errorResponse(Request $request, string $code, int $status): Response
    {
        if ($request->isHtmx() || $request->wantsJson()) {
            return Response::json(['error' => $code], $status);
        }

        return new Response('Error', $status, [
            'Content-Type' => 'text/plain; charset=utf-8',
        ]);
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

    private function typeBadgeClass(string $type): string
    {
        return match ($type) {
            'internal' => 'bg-secondary',
            'admin' => 'bg-dark',
            'api' => 'bg-info text-dark',
            default => 'bg-primary',
        };
    }
}
