<?php
declare(strict_types=1);

use Laas\Ai\Plan;
use Laas\Ai\PlanRunner;
use PHPUnit\Framework\TestCase;

final class PlanRunnerAllowlistConfigTest extends TestCase
{
    private string $rootPath;

    protected function setUp(): void
    {
        $this->rootPath = sys_get_temp_dir() . '/laas_plan_allow_' . bin2hex(random_bytes(4));
        $configDir = $this->rootPath . '/config';
        if (!mkdir($configDir, 0775, true) && !is_dir($configDir)) {
            $this->markTestSkipped('Temp config dir could not be created');
        }

        $config = "<?php\n"
            . "declare(strict_types=1);\n\n"
            . "return [\n"
            . "    'ai_plan_command_allowlist' => [\n"
            . "        'templates:raw:scan',\n"
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
        $plan = new Plan([
            'id' => 'plan_allow_1',
            'created_at' => gmdate(DATE_ATOM),
            'kind' => 'test',
            'summary' => 'Allowlist config',
            'steps' => [
                [
                    'id' => 's1',
                    'title' => 'Raw scan',
                    'command' => 'templates:raw:scan',
                    'args' => [],
                ],
            ],
            'confidence' => 0.5,
            'risk' => 'low',
        ]);

        $runner = new PlanRunner($this->rootPath);
        $result = $runner->run($plan, true, false);

        $this->assertSame('dry-run', $result['outputs'][0]['status'] ?? null);
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
