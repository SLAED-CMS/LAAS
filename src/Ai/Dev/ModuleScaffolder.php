<?php

declare(strict_types=1);

namespace Laas\Ai\Dev;

use InvalidArgumentException;
use Laas\Ai\Proposal;

final class ModuleScaffolder
{
    public function scaffold(string $name, bool $apiEnvelope = true, bool $sandbox = true): Proposal
    {
        $name = trim($name);
        if (!preg_match('/^[A-Z][A-Za-z0-9]{1,49}$/', $name)) {
            throw new InvalidArgumentException('Module name must be PascalCase (2-50 chars, letters/numbers only).');
        }

        $slug = strtolower($name);
        $prefix = $sandbox ? 'storage/sandbox/' : '';
        $moduleDir = $prefix . 'modules/' . $name;

        $moduleJson = $this->buildModuleJson($name);
        $routes = $this->buildRoutes($name, $slug);
        $controller = $this->buildController($name, $slug, $apiEnvelope);
        $moduleClass = $this->buildModuleClass($name);
        $readme = "# {$name}\n\nGenerated module scaffold.\n";

        return new Proposal([
            'id' => bin2hex(random_bytes(16)),
            'created_at' => gmdate(DATE_ATOM),
            'kind' => 'dev.module.scaffold',
            'summary' => 'Scaffold module ' . $name,
            'file_changes' => [
                [
                    'op' => 'create',
                    'path' => $moduleDir . '/module.json',
                    'content' => $moduleJson,
                ],
                [
                    'op' => 'create',
                    'path' => $moduleDir . '/routes.php',
                    'content' => $routes,
                ],
                [
                    'op' => 'create',
                    'path' => $moduleDir . '/Controller/' . $name . 'PingController.php',
                    'content' => $controller,
                ],
                [
                    'op' => 'create',
                    'path' => $moduleDir . '/' . $name . 'Module.php',
                    'content' => $moduleClass,
                ],
                [
                    'op' => 'create',
                    'path' => $moduleDir . '/README.md',
                    'content' => $readme,
                ],
            ],
            'entity_changes' => [],
            'warnings' => ['generated scaffold, review before apply'],
            'confidence' => 1.0,
            'risk' => 'low',
        ]);
    }

    private function buildModuleJson(string $name): string
    {
        return "{\n"
            . "    \"name\":  \"{$name}\",\n"
            . "    \"version\":  \"0.1.0\",\n"
            . "    \"description\":  \"{$name} module scaffold\",\n"
            . "    \"enabled\":  true,\n"
            . "    \"type\":  \"feature\"\n"
            . "}\n";
    }

    private function buildRoutes(string $name, string $slug): string
    {
        return "<?php\n"
            . "declare(strict_types=1);\n\n"
            . "return [\n"
            . "    ['GET', '/{$slug}/ping', [\\Laas\\Modules\\{$name}\\Controller\\{$name}PingController::class, 'ping']],\n"
            . "];\n";
    }

    private function buildController(string $name, string $slug, bool $apiEnvelope): string
    {
        if ($apiEnvelope) {
            return "<?php\n"
                . "declare(strict_types=1);\n\n"
                . "namespace Laas\\Modules\\{$name}\\Controller;\n\n"
                . "use Laas\\Api\\ApiResponse;\n"
                . "use Laas\\Database\\DatabaseManager;\n"
                . "use Laas\\Http\\Request;\n"
                . "use Laas\\Http\\Response;\n"
                . "use Laas\\View\\View;\n\n"
                . "final class {$name}PingController\n"
                . "{\n"
                . "    public function __construct(\n"
                . "        private View \$view,\n"
                . "        private ?DatabaseManager \$db = null\n"
                . "    ) {\n"
                . "    }\n\n"
                . "    public function ping(Request \$request, array \$params = []): Response\n"
                . "    {\n"
                . "        return ApiResponse::ok([\n"
                . "            'ok' => true,\n"
                . "            'module' => '{$slug}',\n"
                . "            'action' => 'ping',\n"
                . "            'ts' => gmdate(DATE_ATOM),\n"
                . "        ]);\n"
                . "    }\n"
                . "}\n";
        }

        return "<?php\n"
            . "declare(strict_types=1);\n\n"
            . "namespace Laas\\Modules\\{$name}\\Controller;\n\n"
            . "use Laas\\Database\\DatabaseManager;\n"
            . "use Laas\\Http\\Request;\n"
            . "use Laas\\Http\\Response;\n"
            . "use Laas\\View\\View;\n\n"
            . "final class {$name}PingController\n"
            . "{\n"
            . "    public function __construct(\n"
            . "        private View \$view,\n"
            . "        private ?DatabaseManager \$db = null\n"
            . "    ) {\n"
            . "    }\n\n"
            . "    public function ping(Request \$request, array \$params = []): Response\n"
            . "    {\n"
            . "        return Response::json([\n"
            . "            'status' => 'ok',\n"
            . "            'module' => '{$slug}',\n"
            . "        ], 200);\n"
            . "    }\n"
            . "}\n";
    }

    private function buildModuleClass(string $name): string
    {
        return "<?php\n"
            . "declare(strict_types=1);\n\n"
            . "namespace Laas\\Modules\\{$name};\n\n"
            . "use Laas\\Core\\Container\\Container;\n"
            . "use Laas\\Database\\DatabaseManager;\n"
            . "use Laas\\Events\\EventDispatcherInterface;\n"
            . "use Laas\\Modules\\ModuleInterface;\n"
            . "use Laas\\Modules\\ModuleLifecycleInterface;\n"
            . "use Laas\\Routing\\RouteHandlerSpec;\n"
            . "use Laas\\Routing\\RouteHandlerTokens;\n"
            . "use Laas\\Routing\\Router;\n"
            . "use Laas\\View\\View;\n\n"
            . "final class {$name}Module implements ModuleInterface, ModuleLifecycleInterface\n"
            . "{\n"
            . "    public function __construct(\n"
            . "        private View \$view,\n"
            . "        private ?DatabaseManager \$db = null\n"
            . "    ) {\n"
            . "    }\n\n"
            . "    public function registerBindings(Container \$container): void\n"
            . "    {\n"
            . "    }\n\n"
            . "    public function registerRoutes(Router \$router): void\n"
            . "    {\n"
            . "        \$contextKey = self::class;\n"
            . "        \$router->registerContext(\$contextKey, [\n"
            . "            'view' => \$this->view,\n"
            . "            'db' => \$this->db,\n"
            . "        ]);\n\n"
            . "        \$routes = require __DIR__ . '/routes.php';\n"
            . "        foreach (\$routes as \$route) {\n"
            . "            [\$method, \$path, \$handler] = \$route;\n"
            . "            if (!is_array(\$handler) || count(\$handler) !== 2) {\n"
            . "                continue;\n"
            . "            }\n\n"
            . "            [\$class, \$action] = \$handler;\n"
            . "            if (!is_string(\$class) || !is_string(\$action)) {\n"
            . "                continue;\n"
            . "            }\n\n"
            . "            \$router->addRoute(\$method, \$path, RouteHandlerSpec::controller(\n"
            . "                \$contextKey,\n"
            . "                \$class,\n"
            . "                \$action,\n"
            . "                [RouteHandlerTokens::TOKEN_VIEW, RouteHandlerTokens::TOKEN_DB],\n"
            . "                true\n"
            . "            ));\n"
            . "        }\n"
            . "    }\n"
            . "\n"
            . "    public function registerListeners(EventDispatcherInterface \$events): void\n"
            . "    {\n"
            . "    }\n"
            . "}\n";
    }
}
