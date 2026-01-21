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

            $readInterfaceFile = substr($serviceFile, 0, -strlen('Service.php')) . 'ReadServiceInterface.php';
            if (is_file($readInterfaceFile)) {
                $readInterfaceClass = $this->classNameFromFile($readInterfaceFile, 'interface');
                $this->assertTrue(interface_exists($readInterfaceClass), 'Missing interface ' . $readInterfaceClass);
                $this->assertTrue(
                    is_subclass_of($serviceClass, $readInterfaceClass),
                    $serviceClass . ' must implement ' . $readInterfaceClass
                );
            }

            $writeInterfaceFile = substr($serviceFile, 0, -strlen('Service.php')) . 'WriteServiceInterface.php';
            if (is_file($writeInterfaceFile)) {
                $writeInterfaceClass = $this->classNameFromFile($writeInterfaceFile, 'interface');
                $this->assertTrue(interface_exists($writeInterfaceClass), 'Missing interface ' . $writeInterfaceClass);
                $this->assertTrue(
                    is_subclass_of($serviceClass, $writeInterfaceClass),
                    $serviceClass . ' must implement ' . $writeInterfaceClass
                );
            }
        }
    }

    public function testServiceInterfacesHaveBindingsAndMethods(): void
    {
        $root = dirname(__DIR__);
        $interfaceFiles = $this->domainServiceInterfaceFiles($root . '/src/Domain');

        $this->assertNotEmpty($interfaceFiles);

        $kernelPath = $root . '/src/Core/Kernel.php';
        $kernelContents = file_get_contents($kernelPath);
        $this->assertNotFalse($kernelContents, 'Unable to read ' . $kernelPath);

        foreach ($interfaceFiles as $interfaceFile) {
            $serviceFile = substr($interfaceFile, 0, -strlen('ServiceInterface.php')) . 'Service.php';
            $this->assertFileExists($serviceFile, 'Missing service for ' . $interfaceFile);

            $interfaceClass = $this->classNameFromFile($interfaceFile, 'interface');
            $serviceClass = $this->classNameFromFile($serviceFile, 'class');

            $this->assertTrue(interface_exists($interfaceClass), 'Missing interface ' . $interfaceClass);
            $this->assertTrue(class_exists($serviceClass), 'Missing class ' . $serviceClass);
            $this->assertTrue(
                is_subclass_of($serviceClass, $interfaceClass),
                $serviceClass . ' must implement ' . $interfaceClass
            );

            $serviceReflection = new ReflectionClass($serviceClass);
            $interfaceReflection = new ReflectionClass($interfaceClass);
            foreach ($interfaceReflection->getMethods() as $method) {
                $this->assertTrue(
                    $serviceReflection->hasMethod($method->getName()),
                    $serviceClass . ' missing method ' . $method->getName()
                );
            }

            $interfaceNeedle = $interfaceClass . '::class';
            $shortNeedle = $this->shortName($interfaceClass) . '::class';
            $this->assertTrue(
                str_contains($kernelContents, $interfaceNeedle) || str_contains($kernelContents, $shortNeedle),
                'Kernel must bind ' . $interfaceClass
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

    /**
     * @return array<int, string>
     */
    private function domainServiceInterfaceFiles(string $root): array
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
            if (!str_ends_with($file->getFilename(), 'ServiceInterface.php')) {
                continue;
            }
            $name = $file->getFilename();
            if (str_ends_with($name, 'ReadServiceInterface.php') || str_ends_with($name, 'WriteServiceInterface.php')) {
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

    private function shortName(string $class): string
    {
        $pos = strrpos($class, '\\');
        return $pos === false ? $class : substr($class, $pos + 1);
    }
}
