<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class ControllerNoRepositoryDepsTest extends TestCase
{
    public function testControllersAvoidRepositoriesAndDb(): void
    {
        $root = dirname(__DIR__, 2);
        $controllers = $this->controllerFiles($root);
        $this->assertNotEmpty($controllers);

        $patterns = [
            '/\\bRepository\\b/',
            '/\\bRepositories\\\\/i',
            '/->db\\b/',
            '/\\bDatabase\\\\/i',
            '/new\\s+\\w*Repository\\b/i',
        ];

        foreach ($controllers as $path) {
            $contents = $this->fileContents($path);
            $scanned = $this->stripComments($contents);
            foreach ($patterns as $pattern) {
                if (preg_match($pattern, $scanned) === 1) {
                    $this->fail('Repository/DB usage detected in controller: ' . $path . ' (' . $pattern . ')');
                }
            }
        }
    }

    /**
     * @return array<int, string>
     */
    private function controllerFiles(string $root): array
    {
        $paths = [
            $root . '/modules',
            $root . '/src/Http/Controller',
        ];

        $files = [];
        foreach ($paths as $base) {
            if (!is_dir($base)) {
                continue;
            }
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS)
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
        }

        sort($files);
        return $files;
    }

    private function fileContents(string $path): string
    {
        $contents = file_get_contents($path);
        if ($contents === false) {
            $this->fail('Unable to read ' . $path);
        }
        return $contents;
    }

    private function stripComments(string $contents): string
    {
        $tokens = token_get_all($contents);
        $out = '';
        foreach ($tokens as $token) {
            if (is_array($token)) {
                $id = $token[0];
                if ($id === T_COMMENT || $id === T_DOC_COMMENT) {
                    continue;
                }
                $out .= $token[1];
                continue;
            }
            $out .= $token;
        }
        return $out;
    }
}
