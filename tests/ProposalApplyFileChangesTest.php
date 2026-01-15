<?php
declare(strict_types=1);

use Laas\Ai\FileChangeApplier;
use PHPUnit\Framework\TestCase;

final class ProposalApplyFileChangesTest extends TestCase
{
    private string $rootPath;

    protected function setUp(): void
    {
        $this->rootPath = sys_get_temp_dir() . '/laas_apply_' . bin2hex(random_bytes(4));
        if (!mkdir($this->rootPath, 0775, true) && !is_dir($this->rootPath)) {
            $this->markTestSkipped('Temp root could not be created');
        }
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->rootPath);
    }

    public function testDryRunDoesNotWrite(): void
    {
        $applier = new FileChangeApplier($this->rootPath);
        $changes = [
            [
                'op' => 'create',
                'path' => 'modules/Demo/README.md',
                'content' => "# Demo\n",
            ],
        ];

        $summary = $applier->apply($changes, true, false);
        $this->assertSame(0, $summary['applied']);
        $this->assertSame(1, $summary['would_apply']);
        $this->assertSame(0, $summary['errors']);
        $this->assertFalse(is_file($this->rootPath . '/modules/Demo/README.md'));
    }

    public function testApplyWithoutYesIsRefused(): void
    {
        $applier = new FileChangeApplier($this->rootPath);
        $changes = [
            [
                'op' => 'create',
                'path' => 'modules/Demo/README.md',
                'content' => "# Demo\n",
            ],
        ];

        $summary = $applier->apply($changes, false, false);
        $this->assertSame(0, $summary['applied']);
        $this->assertSame(0, $summary['would_apply']);
        $this->assertSame(1, $summary['errors']);
        $this->assertFalse(is_file($this->rootPath . '/modules/Demo/README.md'));
    }

    public function testApplyWithYesWritesFile(): void
    {
        $applier = new FileChangeApplier($this->rootPath);
        $changes = [
            [
                'op' => 'create',
                'path' => 'modules/Demo/README.md',
                'content' => "# Demo\n",
            ],
        ];

        $summary = $applier->apply($changes, false, true);
        $this->assertSame(1, $summary['applied']);
        $this->assertSame(0, $summary['would_apply']);
        $this->assertSame(0, $summary['errors']);
        $this->assertTrue(is_file($this->rootPath . '/modules/Demo/README.md'));
        $this->assertSame("# Demo\n", (string) file_get_contents($this->rootPath . '/modules/Demo/README.md'));
    }

    public function testSandboxPathIsAllowedAndWritten(): void
    {
        $applier = new FileChangeApplier($this->rootPath);
        $changes = [
            [
                'op' => 'create',
                'path' => 'storage/sandbox/modules/Demo/README.md',
                'content' => "# Demo\n",
            ],
        ];

        $summary = $applier->apply($changes, false, true);
        $this->assertSame(1, $summary['applied']);
        $this->assertSame(0, $summary['errors']);
        $this->assertTrue(is_file($this->rootPath . '/storage/sandbox/modules/Demo/README.md'));
        $this->assertSame(
            "# Demo\n",
            (string) file_get_contents($this->rootPath . '/storage/sandbox/modules/Demo/README.md')
        );
    }

    public function testPathTraversalIsRejected(): void
    {
        $applier = new FileChangeApplier($this->rootPath);
        $changes = [
            [
                'op' => 'create',
                'path' => '../secrets.txt',
                'content' => 'nope',
            ],
        ];

        $summary = $applier->apply($changes, true, false);
        $this->assertSame(0, $summary['applied']);
        $this->assertSame(0, $summary['would_apply']);
        $this->assertSame(1, $summary['errors']);
    }

    public function testDisallowedPathIsRejected(): void
    {
        $applier = new FileChangeApplier($this->rootPath);
        $changes = [
            [
                'op' => 'create',
                'path' => 'vendor/file.txt',
                'content' => 'nope',
            ],
        ];

        $summary = $applier->apply($changes, true, false);
        $this->assertSame(0, $summary['applied']);
        $this->assertSame(0, $summary['would_apply']);
        $this->assertSame(1, $summary['errors']);
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
