<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class ServiceInterfaceCoverageTest extends TestCase
{
    public function testDomainServicesHaveInterfaces(): void
    {
        $root = dirname(__DIR__);
        $serviceFiles = $this->domainServiceFiles($root . '/src/Domain');

        $this->assertNotEmpty($serviceFiles);

        foreach ($serviceFiles as $serviceFile) {
            $interfaceFile = substr($serviceFile, 0, -strlen('Service.php')) . 'ServiceInterface.php';
            $this->assertFileExists($interfaceFile, 'Missing interface for ' . $serviceFile);

            $serviceClass = $this->classNameFromFile($serviceFile, 'class');
            $interfaceClass = $this->classNameFromFile($interfaceFile, 'interface');

            $this->assertTrue(class_exists($serviceClass), 'Missing class ' . $serviceClass);
            $this->assertTrue(interface_exists($interfaceClass), 'Missing interface ' . $interfaceClass);
            $this->assertTrue(
                is_subclass_of($serviceClass, $interfaceClass),
                $serviceClass . ' must implement ' . $interfaceClass
            );
        }
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
