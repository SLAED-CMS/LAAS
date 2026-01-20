<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class ContainerBindingsSingleRootTest extends TestCase
{
    public function testBindingsOnlyInKernel(): void
    {
        $root = dirname(__DIR__);
        $allowed = [$this->normalizePath($root . '/src/Core/Kernel.php')];

        $violations = [];
        foreach ([$root . '/src', $root . '/modules'] as $path) {
            if (!is_dir($path)) {
                continue;
            }
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS)
            );
            foreach ($iterator as $file) {
                if (!$file->isFile() || strtolower($file->getExtension()) !== 'php') {
                    continue;
                }
                $filePath = $this->normalizePath($file->getPathname());
                if (in_array($filePath, $allowed, true)) {
                    continue;
                }
                $contents = @file_get_contents($filePath);
                if ($contents === false) {
                    continue;
                }
                $scanned = $this->stripComments($contents);
                if (strpos($scanned, '->bind(') !== false || strpos($scanned, '->singleton(') !== false) {
                    $violations[] = $filePath;
                }
            }
        }

        $this->assertSame([], $violations, 'Bindings must live only in src/Core/Kernel.php');
    }

    private function normalizePath(string $path): string
    {
        return str_replace('\\', '/', $path);
    }

    private function stripComments(string $contents): string
    {
        $tokens = token_get_all($contents);
        $out = '';
        $inHeredoc = false;
        foreach ($tokens as $token) {
            if (is_array($token)) {
                $id = $token[0];
                $text = $token[1];
                if ($id === T_START_HEREDOC) {
                    $inHeredoc = true;
                    continue;
                }
                if ($id === T_END_HEREDOC) {
                    $inHeredoc = false;
                    continue;
                }
                if ($inHeredoc) {
                    continue;
                }
                if ($id === T_COMMENT || $id === T_DOC_COMMENT) {
                    continue;
                }
                $out .= $text;
                continue;
            }
            if ($inHeredoc) {
                continue;
            }
            $out .= $token;
        }
        return $out;
    }
}
