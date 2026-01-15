<?php
declare(strict_types=1);

use Laas\Ai\FileChangeApplier;
use PHPUnit\Framework\TestCase;

final class FileChangeApplierAllowlistConfigTest extends TestCase
{
    private string $rootPath;

    protected function setUp(): void
    {
        $this->rootPath = sys_get_temp_dir() . '/laas_apply_allow_' . bin2hex(random_bytes(4));
        $configDir = $this->rootPath . '/config';
        if (!mkdir($configDir, 0775, true) && !is_dir($configDir)) {
            $this->markTestSkipped('Temp config dir could not be created');
        }

        $config = "<?php\n"
            . "declare(strict_types=1);\n\n"
            . "return [\n"
            . "    'ai_file_apply_allowlist_prefixes' => [\n"
            . "        'allowed/',\n"
            . "    ],\n"
            . "];\n";
        file_put_contents($configDir . '/security.php', $config);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->rootPath);
    }

    public function testAllowlistUsesConfig(): void
    {
        $applier = new FileChangeApplier($this->rootPath);
        $summary = $applier->apply([
            [
                'op' => 'create',
                'path' => 'allowed/demo.txt',
                'content' => 'ok',
            ],
        ], true, false);

        $this->assertSame(1, $summary['would_apply'] ?? null);
        $this->assertSame(0, $summary['errors'] ?? null);
    }

    private function removeDir(string $path): void
    {
        if ($path === '' || !is_dir($path)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $item) {
            if ($item->isDir()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }
        @rmdir($path);
    }
}
