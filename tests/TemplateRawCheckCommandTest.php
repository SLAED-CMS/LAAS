<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class TemplateRawCheckCommandTest extends TestCase
{
    public function testCheckFailsOnNewItems(): void
    {
        $root = dirname(__DIR__);
        $fixturePath = $root . '/tests/fixtures/templates_raw_check';
        $allowlistSource = $fixturePath . '/allowlist.json';
        $allowlistPath = $this->copyAllowlist($allowlistSource);

        try {
            [$code, $output] = $this->runCli($root, [
                'templates:raw:check',
                '--path=' . $fixturePath,
                '--allowlist=' . $allowlistPath,
            ]);

            $this->assertSame(3, $code, $output);
            $this->assertStringContainsString('new=1', $output);
        } finally {
            @unlink($allowlistPath);
        }
    }

    public function testUpdateWritesAllowlist(): void
    {
        $root = dirname(__DIR__);
        $fixturePath = $root . '/tests/fixtures/templates_raw_check';
        $allowlistSource = $fixturePath . '/allowlist.json';
        $allowlistPath = $this->copyAllowlist($allowlistSource);

        try {
            [$code, $output] = $this->runCli($root, [
                'templates:raw:check',
                '--path=' . $fixturePath,
                '--allowlist=' . $allowlistPath,
                '--update',
            ]);

            $this->assertSame(0, $code, $output);

            $data = json_decode((string) file_get_contents($allowlistPath), true);
            $this->assertIsArray($data);
            $items = $data['items'] ?? null;
            $this->assertIsArray($items);
            $this->assertCount(1, $items);
            $item = $items[0] ?? [];
            $this->assertSame('raw_block', $item['kind'] ?? null);
            $this->assertSame(2, $item['line'] ?? null);
            $this->assertStringEndsWith('tests/fixtures/templates_raw_check/with_raw.html', (string) ($item['file'] ?? ''));
        } finally {
            @unlink($allowlistPath);
        }
    }

    private function copyAllowlist(string $source): string
    {
        $path = tempnam(sys_get_temp_dir(), 'allowlist-');
        if ($path === false) {
            $this->markTestSkipped('Temp file could not be created');
        }
        $content = (string) file_get_contents($source);
        file_put_contents($path, $content);
        return $path;
    }

    /**
     * @return array{0:int,1:string}
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

            $combined = (string) $output . (string) $error;
            return [(int) $code, $combined];
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

            $parts = array_map('escapeshellarg', $args);
            $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/tools/cli.php') . ' ' . implode(' ', $parts) . ' 2>&1';
            $output = [];
            $code = 0;
            exec($cmd, $output, $code);

            $_ENV = $backup;
            foreach (['DB_DRIVER', 'DB_DATABASE', 'DB_NAME', 'DB_HOST', 'DB_USER', 'DB_PASSWORD', 'DB_PORT'] as $key) {
                $value = $backup[$key] ?? null;
                if ($value === null || $value === '') {
                    putenv($key);
                } else {
                    putenv($key . '=' . $value);
                }
            }

            return [(int) $code, implode("\n", $output)];
        }

        $this->markTestSkipped('CLI execution not available');
    }
}
