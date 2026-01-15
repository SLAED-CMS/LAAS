<?php
declare(strict_types=1);

use Laas\Ai\Dev\ModuleScaffolder;
use PHPUnit\Framework\TestCase;

final class DevModuleScaffolderTest extends TestCase
{
    public function testScaffoldBuildsProposal(): void
    {
        $scaffolder = new ModuleScaffolder();
        $proposal = $scaffolder->scaffold('Blog');
        $data = $proposal->toArray();

        $this->assertSame('dev.module.scaffold', $data['kind'] ?? null);
        $this->assertIsArray($data['file_changes'] ?? null);
        $this->assertGreaterThanOrEqual(3, count($data['file_changes']));

        foreach ($data['file_changes'] as $change) {
            $path = (string) ($change['path'] ?? '');
            $this->assertStringStartsWith('storage/sandbox/modules/Blog/', $path);
        }

        $routes = $this->findFileChange($data['file_changes'], 'storage/sandbox/modules/Blog/routes.php');
        $this->assertNotNull($routes);
        $this->assertStringContainsString("/blog/ping", (string) ($routes['content'] ?? ''));

        $controller = $this->findFileChange($data['file_changes'], 'storage/sandbox/modules/Blog/Controller/BlogPingController.php');
        $this->assertNotNull($controller);
        $this->assertStringContainsString('ApiResponse::ok', (string) ($controller['content'] ?? ''));
    }

    public function testInvalidNameThrows(): void
    {
        $scaffolder = new ModuleScaffolder();
        $this->expectException(InvalidArgumentException::class);
        $scaffolder->scaffold('my_module');
    }

    public function testScaffoldWithoutEnvelopeUsesResponseJson(): void
    {
        $scaffolder = new ModuleScaffolder();
        $proposal = $scaffolder->scaffold('Plain', false, false);
        $data = $proposal->toArray();

        $controller = $this->findFileChange($data['file_changes'], 'modules/Plain/Controller/PlainPingController.php');
        $this->assertNotNull($controller);
        $this->assertStringContainsString('Response::json', (string) ($controller['content'] ?? ''));
    }

    public function testScaffoldWithoutSandboxUsesModulesPath(): void
    {
        $scaffolder = new ModuleScaffolder();
        $proposal = $scaffolder->scaffold('Real', true, false);
        $data = $proposal->toArray();

        foreach ($data['file_changes'] as $change) {
            $path = (string) ($change['path'] ?? '');
            $this->assertStringStartsWith('modules/Real/', $path);
        }
    }

    /**
     * @param array<int, array<string, mixed>> $changes
     * @return array<string, mixed>|null
     */
    private function findFileChange(array $changes, string $path): ?array
    {
        foreach ($changes as $change) {
            if (($change['path'] ?? '') === $path) {
                return $change;
            }
        }
        return null;
    }
}
