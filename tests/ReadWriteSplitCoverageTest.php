<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class ReadWriteSplitCoverageTest extends TestCase
{
    public function testWriteInterfacesHaveMutationMarkers(): void
    {
        $root = dirname(__DIR__);
        $writeInterfaces = $this->interfaceFiles($root . '/src/Domain', 'WriteServiceInterface.php');
        $this->assertNotEmpty($writeInterfaces);

        foreach ($writeInterfaces as $path) {
            $class = $this->classNameFromFile($path, 'interface');
            if ($class === '') {
                continue;
            }

            $this->assertTrue(interface_exists($class), 'Missing interface class ' . $class);
            $reflection = new ReflectionClass($class);

            foreach ($reflection->getMethods() as $method) {
                $doc = $method->getDocComment() ?: '';
                $this->assertStringContainsString(
                    '@mutation',
                    $doc,
                    'Missing @mutation on ' . $class . '::' . $method->getName()
                );
            }
        }
    }

    public function testReadInterfacesHaveNoMutationMarkers(): void
    {
        $root = dirname(__DIR__);
        $readInterfaces = $this->interfaceFiles($root . '/src/Domain', 'ReadServiceInterface.php');
        $this->assertNotEmpty($readInterfaces);

        foreach ($readInterfaces as $path) {
            $class = $this->classNameFromFile($path, 'interface');
            if ($class === '') {
                continue;
            }

            $this->assertTrue(interface_exists($class), 'Missing interface class ' . $class);
            $reflection = new ReflectionClass($class);

            foreach ($reflection->getMethods() as $method) {
                $doc = $method->getDocComment() ?: '';
                $this->assertStringNotContainsString(
                    '@mutation',
                    $doc,
                    'Unexpected @mutation on ' . $class . '::' . $method->getName()
                );
            }
        }
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
