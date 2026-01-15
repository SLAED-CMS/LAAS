<?php
declare(strict_types=1);

use Laas\Ai\Proposal;
use Laas\Ai\ProposalStore;
use PHPUnit\Framework\TestCase;

final class ProposalContractTest extends TestCase
{
    private string $rootPath;

    protected function setUp(): void
    {
        $this->rootPath = sys_get_temp_dir() . '/laas_proposal_' . bin2hex(random_bytes(4));
        if (!mkdir($this->rootPath, 0775, true) && !is_dir($this->rootPath)) {
            $this->markTestSkipped('Temp root could not be created');
        }
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->rootPath);
    }

    public function testProposalRoundtrip(): void
    {
        $data = [
            'id' => 'demo_1',
            'created_at' => gmdate(DATE_ATOM),
            'kind' => 'demo',
            'summary' => 'Demo proposal scaffold',
            'file_changes' => [
                ['op' => 'create', 'path' => 'modules/Demo/README.md'],
            ],
            'entity_changes' => [],
            'warnings' => ['demo only'],
            'confidence' => 0.5,
            'risk' => 'low',
        ];

        $proposal = new Proposal($data);
        $store = new ProposalStore($this->rootPath);
        $path = $store->save($proposal);
        $this->assertTrue(is_file($path));

        $loaded = $store->load('demo_1');
        $payload = $loaded->toArray();

        $this->assertSame('demo_1', $payload['id'] ?? null);
        $this->assertSame('demo', $payload['kind'] ?? null);
        $this->assertSame('low', $payload['risk'] ?? null);
        $this->assertIsArray($payload['file_changes'] ?? null);
        $this->assertIsArray($payload['entity_changes'] ?? null);
        $this->assertIsArray($payload['warnings'] ?? null);
        $this->assertIsFloat($payload['confidence'] ?? null);
    }

    public function testRiskValidation(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Proposal([
            'id' => 'demo_2',
            'created_at' => gmdate(DATE_ATOM),
            'kind' => 'demo',
            'summary' => 'Bad risk',
            'file_changes' => [],
            'entity_changes' => [],
            'warnings' => [],
            'confidence' => 0.5,
            'risk' => 'critical',
        ]);
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
