<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class MutationMarkerTest extends TestCase
{
    private const MUTATION_PATTERN =
        '/^(?:create|update|delete|remove|apply|save|store|upload|revoke|set|triage|rotate|enable|disable)(?:$|[A-Z0-9_])/';

    public function testMutatingMethodsHaveMarkers(): void
    {
        $root = dirname(__DIR__);
        $serviceFiles = $this->domainServiceFiles($root . '/src/Domain');
        $this->assertNotEmpty($serviceFiles);

        $allowlist = $this->mutationAllowlist();

        foreach ($serviceFiles as $serviceFile) {
            $class = $this->classNameFromFile($serviceFile, 'class');
            if ($class === '') {
                continue;
            }
            $this->assertTrue(class_exists($class), 'Missing class ' . $class);
            $reflection = new ReflectionClass($class);

            foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                if ($method->isConstructor() || $method->isDestructor() || $method->isStatic()) {
                    continue;
                }
                if ($method->getDeclaringClass()->getName() !== $class) {
                    continue;
                }
                $name = $method->getName();
                if (!preg_match(self::MUTATION_PATTERN, $name)) {
                    continue;
                }
                if ($this->isAllowlisted($allowlist, $class, $name)) {
                    continue;
                }

                $doc = $method->getDocComment();
                if ($doc === false || stripos($doc, '@mutation') === false) {
                    $this->fail($class . '::' . $name . ' must declare @mutation');
                }
            }
        }
    }

    /**
     * @return array<string, array<int, string>>
     */
    private function mutationAllowlist(): array
    {
        return [];
    }

    /**
     * @param array<string, array<int, string>> $allowlist
     */
    private function isAllowlisted(array $allowlist, string $class, string $method): bool
    {
        $methods = $allowlist[$class] ?? [];
        return in_array($method, $methods, true);
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
            if (str_ends_with($name, 'ServiceInterface.php') || str_ends_with($name, 'ServiceException.php')) {
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
            $this->fail('Missing namespace in ' . $path);
        }
        $namespace = trim((string) ($nsMatch[1] ?? ''));
        if ($namespace === '') {
            $this->fail('Empty namespace in ' . $path);
        }

        $pattern = $type === 'interface'
            ? '/^\\s*interface\\s+(\\w+)/m'
            : '/^\\s*(?:final\\s+|abstract\\s+)?class\\s+(\\w+)/m';
        if (!preg_match($pattern, $contents, $classMatch)) {
            $this->fail('Missing ' . $type . ' declaration in ' . $path);
        }

        $class = trim((string) ($classMatch[1] ?? ''));
        if ($class === '') {
            $this->fail('Empty ' . $type . ' name in ' . $path);
        }

        return $namespace . '\\' . $class;
    }
}
