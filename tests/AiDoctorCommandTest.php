<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class AiDoctorCommandTest extends TestCase
{
    public function testDoctorOutputsBasics(): void
    {
        $root = dirname(__DIR__);
        [$code, $stdout, $stderr] = $this->runCli($root, ['ai:doctor']);
        $this->assertSame(0, $code, $stdout . $stderr);
        $this->assertStringContainsString('storage/proposals', $stdout);
        $this->assertStringContainsString('db_driver=', $stdout);
    }

    public function testDoctorFixCreatesDirs(): void
    {
        $root = dirname(__DIR__);
        [$code, $stdout, $stderr] = $this->runCli($root, ['ai:doctor', '--fix']);
        $this->assertSame(0, $code, $stdout . $stderr);
        $this->assertTrue(is_dir($root . '/storage/proposals'));
        $this->assertTrue(is_dir($root . '/storage/plans'));
        $this->assertTrue(is_dir($root . '/storage/sandbox'));
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
