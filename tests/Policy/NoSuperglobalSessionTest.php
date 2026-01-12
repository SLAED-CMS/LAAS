<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class NoSuperglobalSessionTest extends TestCase
{
    public function testNoSessionSuperglobalUsage(): void
    {
        $root = dirname(__DIR__, 2);
        $allowed = realpath($root . '/src/Session/NativeSession.php');
        $needle = '$' . '_SESSION';
        $violations = [];

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $path = $file->getPathname();
            if (str_contains($path, DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR)) {
                continue;
            }
            if (str_contains($path, DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR)) {
                continue;
            }
            if (str_contains($path, DIRECTORY_SEPARATOR . '.git' . DIRECTORY_SEPARATOR)) {
                continue;
            }
            if (str_contains($path, DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR . 'fixtures')) {
                continue;
            }

            if ($allowed !== false && realpath($path) === $allowed) {
                continue;
            }

            $contents = file_get_contents($path);
            if ($contents !== false && str_contains($contents, $needle)) {
                $violations[] = $path;
            }
        }

        $this->assertSame([], $violations, "Found " . $needle . " usage:\n" . implode("\n", $violations));
    }
}
