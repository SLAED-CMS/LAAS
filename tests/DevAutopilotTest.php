<?php
declare(strict_types=1);

use Laas\Ai\Dev\DevAutopilot;
use PHPUnit\Framework\TestCase;

final class DevAutopilotTest extends TestCase
{
    private string $rootPath;

    protected function setUp(): void
    {
        $this->rootPath = sys_get_temp_dir() . '/laas_autopilot_' . bin2hex(random_bytes(4));
        if (!mkdir($this->rootPath, 0775, true) && !is_dir($this->rootPath)) {
            $this->markTestSkipped('Temp root could not be created');
        }
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->rootPath);
    }

    public function testAutopilotDryRun(): void
    {
        $autopilot = new DevAutopilot($this->rootPath);
        $result = $autopilot->run('AutoMod', true, true, false);

        $this->assertSame('dry-run', $result['mode'] ?? null);
        $this->assertNotSame('', $result['proposal_id'] ?? '');
        $this->assertSame(1, $result['proposal_valid'] ?? null);
        $this->assertSame(0, $result['applied'] ?? null);
        $this->assertNotSame('', $result['plan_id'] ?? '');
        $this->assertStringContainsString('storage/sandbox/modules/AutoMod', (string) ($result['module_path'] ?? ''));
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
