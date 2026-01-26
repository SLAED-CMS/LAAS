<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class ContainerAuditCommandTest extends TestCase
{
    public function testContainerAuditOutputsStableBindings(): void
    {
        $root = dirname(__DIR__, 2);
        [$code, $output] = $this->runCli($root, ['container:audit']);

        $this->assertSame(0, $code, $output);
        $this->assertStringContainsString(
            'Laas\\Domain\\Pages\\PagesReadServiceInterface',
            $output
        );
        $this->assertStringContainsString(
            'Laas\\Domain\\Pages\\PagesWriteServiceInterface',
            $output
        );

        $lines = array_values(array_filter(array_map('trim', explode("\n", $output)), static fn(string $line): bool => $line !== ''));
        $this->assertNotEmpty($lines);
        $this->assertSame(
            'Laas\\Admin\\Editors\\EditorProvidersRegistry => Laas\\Admin\\Editors\\EditorProvidersRegistry | singleton',
            $lines[0]
        );
        $this->assertSame(
            'translator => Laas\\I18n\\Translator | singleton',
            $lines[count($lines) - 1]
        );

        $ids = [];
        foreach ($lines as $line) {
            $parts = explode(' => ', $line, 2);
            $ids[] = $parts[0];
        }
        $sorted = $ids;
        sort($sorted);
        $this->assertSame($sorted, $ids);
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
