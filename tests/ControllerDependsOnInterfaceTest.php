<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class ControllerDependsOnInterfaceTest extends TestCase
{
    public function testControllersAvoidConcreteDomainServices(): void
    {
        $root = dirname(__DIR__);
        $serviceClasses = $this->domainServiceClasses($root . '/src/Domain');
        $this->assertNotEmpty($serviceClasses);

        $controllers = $this->controllerFiles($root . '/modules');
        $this->assertNotEmpty($controllers);

        $serviceShortNames = array_map([$this, 'shortName'], $serviceClasses);

        foreach ($controllers as $controllerFile) {
            $class = $this->classNameFromFile($controllerFile, 'class');
            if ($class === '') {
                continue;
            }

            $this->assertTrue(class_exists($class), 'Missing controller class ' . $class);
            $reflection = new ReflectionClass($class);

            foreach ($reflection->getProperties() as $property) {
                $this->assertNoConcreteServiceType(
                    $property->getType(),
                    $serviceClasses,
                    $class . '::$' . $property->getName()
                );
            }

            foreach ($reflection->getMethods() as $method) {
                foreach ($method->getParameters() as $parameter) {
                    $this->assertNoConcreteServiceType(
                        $parameter->getType(),
                        $serviceClasses,
                        $class . '::' . $method->getName() . '()'
                    );
                }
            }

            $this->assertNoConcreteServiceNew($controllerFile, $serviceClasses, $serviceShortNames);
        }
    }

    private function assertNoConcreteServiceType(?ReflectionType $type, array $services, string $context): void
    {
        if ($type instanceof ReflectionUnionType) {
            foreach ($type->getTypes() as $inner) {
                $this->assertNoConcreteServiceType($inner, $services, $context);
            }
            return;
        }

        if ($type instanceof ReflectionIntersectionType) {
            foreach ($type->getTypes() as $inner) {
                $this->assertNoConcreteServiceType($inner, $services, $context);
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
        if (in_array($name, $services, true)) {
            $this->fail('Concrete service dependency found in ' . $context . ': ' . $name);
        }
    }

    private function assertNoConcreteServiceNew(string $path, array $serviceClasses, array $shortNames): void
    {
        $contents = file_get_contents($path);
        if ($contents === false) {
            $this->fail('Unable to read ' . $path);
        }

        $tokens = token_get_all($contents);
        $count = count($tokens);
        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];
            if (!is_array($token) || $token[0] !== T_NEW) {
                continue;
            }
            $name = $this->nextClassNameToken($tokens, $i + 1);
            if ($name === '') {
                continue;
            }
            $normalized = ltrim($name, '\\');
            $short = $this->shortName($normalized);
            if (in_array($normalized, $serviceClasses, true) || in_array($short, $shortNames, true)) {
                $this->fail('Concrete service instantiation found in ' . $path . ': ' . $name);
            }
        }
    }

    private function nextClassNameToken(array $tokens, int $start): string
    {
        $nameTokens = [T_STRING];
        if (defined('T_NAME_QUALIFIED')) {
            $nameTokens[] = T_NAME_QUALIFIED;
        }
        if (defined('T_NAME_FULLY_QUALIFIED')) {
            $nameTokens[] = T_NAME_FULLY_QUALIFIED;
        }

        $count = count($tokens);
        for ($i = $start; $i < $count; $i++) {
            $token = $tokens[$i];
            if (is_array($token)) {
                if (in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }
                if (in_array($token[0], $nameTokens, true)) {
                    return (string) $token[1];
                }
                return '';
            }
            if (trim($token) === '') {
                continue;
            }
            return '';
        }

        return '';
    }

    /**
     * @return array<int, string>
     */
    private function domainServiceFiles(string $root): array
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
            $name = $file->getFilename();
            if (!str_ends_with($name, 'Service.php')) {
                continue;
            }
            if (
                str_ends_with($name, 'ServiceInterface.php')
                || str_ends_with($name, 'ReadServiceInterface.php')
                || str_ends_with($name, 'WriteServiceInterface.php')
                || str_ends_with($name, 'ServiceException.php')
            ) {
                continue;
            }
            $files[] = $file->getPathname();
        }

        sort($files);
        return $files;
    }

    /**
     * @return array<int, string>
     */
    private function domainServiceClasses(string $root): array
    {
        $classes = [];
        foreach ($this->domainServiceFiles($root) as $path) {
            $class = $this->classNameFromFile($path, 'class');
            if ($class !== '') {
                $classes[] = $class;
            }
        }

        sort($classes);
        return $classes;
    }

    /**
     * @return array<int, string>
     */
    private function controllerFiles(string $root): array
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
            if (!str_ends_with($file->getFilename(), 'Controller.php')) {
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

    private function shortName(string $class): string
    {
        $pos = strrpos($class, '\\');
        return $pos === false ? $class : substr($class, $pos + 1);
    }
}
