<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class ProposalValidateCommandTest extends TestCase
{
    public function testValidProposalPath(): void
    {
        $root = dirname(__DIR__);
        $path = $this->writeTempProposal([
            'id' => 'proposal_1',
            'created_at' => gmdate(DATE_ATOM),
            'kind' => 'demo',
            'summary' => 'Demo proposal',
            'file_changes' => [
                [
                    'op' => 'create',
                    'path' => 'modules/Demo/README.md',
                    'content' => "# Demo\n",
                ],
            ],
            'entity_changes' => [],
            'warnings' => [],
            'confidence' => 0.7,
            'risk' => 'low',
        ]);

        try {
            [$code, $stdout, $stderr] = $this->runCli($root, ['ai:proposal:validate', $path]);
            $this->assertSame(0, $code, $stdout . $stderr);
            $this->assertStringContainsString('valid=1', $stdout);
        } finally {
            @unlink($path);
        }
    }

    public function testInvalidConfidenceFails(): void
    {
        $root = dirname(__DIR__);
        $path = $this->writeTempProposal([
            'id' => 'proposal_2',
            'created_at' => gmdate(DATE_ATOM),
            'kind' => 'demo',
            'summary' => 'Bad proposal',
            'file_changes' => [],
            'entity_changes' => [],
            'warnings' => [],
            'confidence' => 2.5,
            'risk' => 'low',
        ]);

        try {
            [$code, $stdout, $stderr] = $this->runCli($root, ['ai:proposal:validate', $path, '--json']);
            $this->assertSame(3, $code, $stdout . $stderr);
            $data = json_decode($stdout, true);
            $this->assertIsArray($data);
            $this->assertSame(false, $data['valid'] ?? null);
            $this->assertNotEmpty($data['errors'] ?? []);
            $first = $data['errors'][0] ?? [];
            $this->assertStringContainsString('confidence', (string) ($first['path'] ?? ''));
        } finally {
            @unlink($path);
        }
    }

    public function testMissingRequiredKeyFails(): void
    {
        $root = dirname(__DIR__);
        $path = $this->writeTempProposal([
            'id' => 'proposal_3',
            'created_at' => gmdate(DATE_ATOM),
            'kind' => 'demo',
            'summary' => 'Missing warnings',
            'file_changes' => [],
            'entity_changes' => [],
            'confidence' => 0.3,
            'risk' => 'low',
        ]);

        try {
            [$code, $stdout, $stderr] = $this->runCli($root, ['ai:proposal:validate', $path]);
            $this->assertSame(3, $code, $stdout . $stderr);
            $this->assertStringContainsString('warnings', $stdout);
        } finally {
            @unlink($path);
        }
    }

    private function writeTempProposal(array $data): string
    {
        $path = tempnam(sys_get_temp_dir(), 'laas-proposal-');
        if ($path === false) {
            $this->markTestSkipped('Temp file could not be created');
        }

        file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        return $path;
    }

    /**
     * @return array{0:int,1:string,2:string}
     */
    private function runCli(string $root, array $args): array
    {
        if (function_exists('proc_open')) {
            $env = $_ENV;
            $env['DB_DRIVER'] = 'sqlite';
            $env['DB_DATABASE'] = ':memory:';
            $env['DB_NAME'] = ':memory:';
            $env['DB_HOST'] = '';
            $env['DB_USER'] = '';
            $env['DB_PASSWORD'] = '';
            $env['DB_PORT'] = '';
            $cmd = array_merge([PHP_BINARY, $root . '/tools/cli.php'], $args);
            $descriptors = [
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ];
            $process = proc_open($cmd, $descriptors, $pipes, $root, $env);
            $this->assertIsResource($process);

            $output = stream_get_contents($pipes[1]);
            $error = stream_get_contents($pipes[2]);
            foreach ($pipes as $pipe) {
                fclose($pipe);
            }
            $code = proc_close($process);

            return [(int) $code, (string) $output, (string) $error];
        }

        if (function_exists('exec')) {
            $backup = $_ENV;
            $_ENV['DB_DRIVER'] = 'sqlite';
            $_ENV['DB_DATABASE'] = ':memory:';
            $_ENV['DB_NAME'] = ':memory:';
            $_ENV['DB_HOST'] = '';
            $_ENV['DB_USER'] = '';
            $_ENV['DB_PASSWORD'] = '';
            $_ENV['DB_PORT'] = '';
            foreach (['DB_DRIVER', 'DB_DATABASE', 'DB_NAME', 'DB_HOST', 'DB_USER', 'DB_PASSWORD', 'DB_PORT'] as $key) {
                putenv($key . '=' . ($_ENV[$key] ?? ''));
            }

            $stdoutPath = tempnam(sys_get_temp_dir(), 'laas-cli-out-');
            $stderrPath = tempnam(sys_get_temp_dir(), 'laas-cli-err-');
            if ($stdoutPath === false || $stderrPath === false) {
                $this->markTestSkipped('Temp files could not be created');
            }
            $parts = array_map('escapeshellarg', $args);
            $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/tools/cli.php') . ' ' . implode(' ', $parts);
            $cmd .= ' 1> ' . escapeshellarg($stdoutPath) . ' 2> ' . escapeshellarg($stderrPath);
            $output = [];
            $code = 0;
            exec($cmd, $output, $code);

            $stdout = is_file($stdoutPath) ? (string) file_get_contents($stdoutPath) : '';
            $stderr = is_file($stderrPath) ? (string) file_get_contents($stderrPath) : '';
            @unlink($stdoutPath);
            @unlink($stderrPath);

            $_ENV = $backup;
            foreach (['DB_DRIVER', 'DB_DATABASE', 'DB_NAME', 'DB_HOST', 'DB_USER', 'DB_PASSWORD', 'DB_PORT'] as $key) {
                $value = $backup[$key] ?? null;
                if ($value === null || $value === '') {
                    putenv($key);
                } else {
                    putenv($key . '=' . $value);
                }
            }

            return [(int) $code, $stdout, $stderr];
        }

        $this->markTestSkipped('CLI execution not available');
    }
}
