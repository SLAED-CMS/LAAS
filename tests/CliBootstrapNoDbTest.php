<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class CliBootstrapNoDbTest extends TestCase
{
    public function testNonDbCommandDoesNotRequireDb(): void
    {
        $root = dirname(__DIR__);
        [$code, $stdout, $stderr] = $this->runCli($root, ['policy:check']);
        $this->assertSame(0, $code, $stdout . $stderr);
    }

    /**
     * @return array{0:int,1:string,2:string}
     */
    private function runCli(string $root, array $args): array
    {
        if (function_exists('proc_open')) {
            $env = $_ENV;
            $env['DB_DRIVER'] = 'mariadb';
            $env['DB_DATABASE'] = 'invalid_db';
            $env['DB_NAME'] = 'invalid_db';
            $env['DB_HOST'] = 'invalid_host';
            $env['DB_USER'] = 'invalid_user';
            $env['DB_PASSWORD'] = 'invalid_pass';
            $env['DB_PORT'] = '3306';
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
            $_ENV['DB_DRIVER'] = 'mariadb';
            $_ENV['DB_DATABASE'] = 'invalid_db';
            $_ENV['DB_NAME'] = 'invalid_db';
            $_ENV['DB_HOST'] = 'invalid_host';
            $_ENV['DB_USER'] = 'invalid_user';
            $_ENV['DB_PASSWORD'] = 'invalid_pass';
            $_ENV['DB_PORT'] = '3306';
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
