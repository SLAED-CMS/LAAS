<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class DoctorCommandTest extends TestCase
{
    public function testDoctorCommand(): void
    {
        $root = dirname(__DIR__, 2);

        if (function_exists('proc_open')) {
            $env = $_ENV;
            $env['DB_DRIVER'] = 'sqlite';
            $env['DB_DATABASE'] = ':memory:';
            $env['DB_NAME'] = ':memory:';
            $env['DB_HOST'] = '';
            $env['DB_USER'] = '';
            $env['DB_PASSWORD'] = '';
            $env['DB_PORT'] = '';

            $cmd = [PHP_BINARY, $root . '/tools/cli.php', 'doctor'];
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
            $this->assertSame(0, $code, $combined);
            $this->assertStringContainsString('PHP', $combined);
            $this->assertStringContainsString('STORAGE_DISK', $combined);
            $this->assertStringNotContainsString('DB_PASSWORD', $combined);
            return;
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

            $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/tools/cli.php') . ' doctor 2>&1';
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

            $combined = implode("\n", $output);
            $this->assertSame(0, $code, $combined);
            $this->assertStringContainsString('PHP', $combined);
            $this->assertStringContainsString('STORAGE_DISK', $combined);
            $this->assertStringNotContainsString('DB_PASSWORD', $combined);
            return;
        }

        $this->markTestSkipped('CLI execution not available');
    }
}
