<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class ContainerBindingsSingleRootTest extends TestCase
{
    public function testBindingsOnlyInKernel(): void
    {
        $root = dirname(__DIR__);
        $allowed = [
            $this->normalizePath($root . '/src/Core/Kernel.php'),
            $this->normalizePath($root . '/src/Core/Bindings/CoreBindings.php'),
            $this->normalizePath($root . '/src/Core/Bindings/DomainBindings.php'),
            $this->normalizePath($root . '/src/Core/Bindings/ModuleBindings.php'),
            $this->normalizePath($root . '/src/Core/Bindings/DevBindings.php'),
        ];

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

        $this->assertSame([], $violations, 'Bindings must live only in Kernel or binding providers.');
    }

    public function testKernelRegistersBindingProvidersInOrder(): void
    {
        $root = dirname(__DIR__);
        $kernelPath = $root . '/src/Core/Kernel.php';
        $contents = @file_get_contents($kernelPath);
        $this->assertIsString($contents);
        $scanned = $this->stripComments($contents);
        $start = strpos($scanned, 'private function registerBindings');
        $this->assertNotFalse($start, 'Kernel registerBindings method not found.');
        $scanned = substr($scanned, (int) $start);

        $needles = [
            'CoreBindings::register',
            'DomainBindings::register',
            'ModuleBindings::register',
            'DevBindings::register',
        ];

        $positions = [];
        foreach ($needles as $needle) {
            $pos = strpos($scanned, $needle);
            $this->assertNotFalse($pos, 'Missing provider call: ' . $needle);
            $positions[] = $pos;
        }

        $sorted = $positions;
        sort($sorted);
        $this->assertSame($sorted, $positions, 'Provider calls must be in deterministic order.');

        $this->assertSame(false, strpos($scanned, '->bind('), 'Kernel must not register bindings directly.');
        $this->assertSame(false, strpos($scanned, '->singleton('), 'Kernel must not register bindings directly.');
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
