<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class ControllerReadOnlyDepsTest extends TestCase
{
    public function testReadOnlyControllersDependOnReadInterfaces(): void
    {
        $root = dirname(__DIR__);
        $controllerMethods = $this->controllerHttpMethods($root . '/modules');
        $controllerMethods[\Laas\Modules\System\Controller\HomeController::class][] = 'GET';
        $this->assertNotEmpty($controllerMethods);

        $disallowed = $this->disallowedServiceInterfaces($root . '/src/Domain');
        $this->assertNotEmpty($disallowed);

        foreach ($controllerMethods as $class => $methods) {
            $methods = array_unique($methods);
            if (!$this->onlyReadMethods($methods)) {
                continue;
            }

            $this->assertTrue(class_exists($class), 'Missing controller class ' . $class);
            $reflection = new ReflectionClass($class);

            foreach ($reflection->getProperties() as $property) {
                $this->assertNoDisallowedServiceType(
                    $property->getType(),
                    $disallowed,
                    $class . '::$' . $property->getName()
                );
            }

            foreach ($reflection->getMethods() as $method) {
                foreach ($method->getParameters() as $parameter) {
                    $this->assertNoDisallowedServiceType(
                        $parameter->getType(),
                        $disallowed,
                        $class . '::' . $method->getName() . '()'
                    );
                }
            }
        }
    }

    private function onlyReadMethods(array $methods): bool
    {
        foreach ($methods as $method) {
            $method = strtoupper((string) $method);
            if (!in_array($method, ['GET', 'HEAD'], true)) {
                return false;
            }
        }

        return true;
    }

    private function assertNoDisallowedServiceType(?ReflectionType $type, array $disallowed, string $context): void
    {
        if ($type instanceof ReflectionUnionType) {
            foreach ($type->getTypes() as $inner) {
                $this->assertNoDisallowedServiceType($inner, $disallowed, $context);
            }
            return;
        }

        if ($type instanceof ReflectionIntersectionType) {
            foreach ($type->getTypes() as $inner) {
                $this->assertNoDisallowedServiceType($inner, $disallowed, $context);
            }
            return;
        }

        if (!$type instanceof ReflectionNamedType) {
            return;
        }

        if ($type->isBuiltin()) {
            return;
        }

        $name = $type->getName();
        if (in_array($name, $disallowed, true)) {
            $this->fail('Write-capable dependency found in read-only controller: ' . $context . ' => ' . $name);
        }
    }

    /**
     * @return array<string, array<int, string>>
     */
    private function controllerHttpMethods(string $root): array
    {
        $controllers = [];
        if (!is_dir($root)) {
            return $controllers;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }
            if ($file->getFilename() !== 'routes.php') {
                continue;
            }

            $routes = require $file->getPathname();
            if (!is_array($routes)) {
                continue;
            }

            foreach ($routes as $route) {
                if (!is_array($route) || count($route) < 3) {
                    continue;
                }

                $method = strtoupper((string) ($route[0] ?? ''));
                $handler = $route[2] ?? null;
                if (!is_array($handler) || count($handler) !== 2) {
                    continue;
                }
                [$class, $action] = $handler;
                if (!is_string($class) || !is_string($action)) {
                    continue;
                }

                $controllers[$class][] = $method;
            }
        }

        return $controllers;
    }

    /**
     * @return array<int, string>
     */
    private function disallowedServiceInterfaces(string $root): array
    {
        $disallowed = [];
        $readInterfaces = $this->interfaceFiles($root, 'ReadServiceInterface.php');

        foreach ($readInterfaces as $path) {
            $class = $this->classNameFromFile($path, 'interface');
            if ($class === '') {
                continue;
            }

            $disallowed[] = str_replace('ReadServiceInterface', 'ServiceInterface', $class);
            $disallowed[] = str_replace('ReadServiceInterface', 'WriteServiceInterface', $class);
        }

        $disallowed = array_values(array_unique($disallowed));
        sort($disallowed);
        return $disallowed;
    }

    /**
     * @return array<int, string>
     */
    private function interfaceFiles(string $root, string $suffix): array
    {
        $files = [];
        if (!is_dir($root)) {
            return $files;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }
            if (!str_ends_with($file->getFilename(), $suffix)) {
                continue;
            }
            $files[] = $file->getPathname();
        }

        sort($files);
        return $files;
    }

    private function classNameFromFile(string $path, string $type): string
    {
        $contents = file_get_contents($path);
        if ($contents === false) {
            $this->fail('Unable to read ' . $path);
        }

        if (!preg_match('/^namespace\\s+([^;]+);/m', $contents, $nsMatch)) {
            return '';
        }
        $namespace = trim((string) ($nsMatch[1] ?? ''));
        if ($namespace === '') {
            return '';
        }

        $pattern = $type === 'interface'
            ? '/^\\s*interface\\s+(\\w+)/m'
            : '/^\\s*(?:final\\s+|abstract\\s+)?class\\s+(\\w+)/m';
        if (!preg_match($pattern, $contents, $classMatch)) {
            return '';
        }

        $class = trim((string) ($classMatch[1] ?? ''));
        if ($class === '') {
            return '';
        }

        return $namespace . '\\' . $class;
    }
}
