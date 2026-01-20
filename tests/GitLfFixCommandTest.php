<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class GitLfFixCommandTest extends TestCase
{
    public function testFixesTrackedFileCrlf(): void
    {
        $root = dirname(__DIR__);
        $dir = $root . '/tests/fixtures/git-lf-fix';
        $path = $dir . '/bad.css';
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        file_put_contents($path, "body{\r\ncolor:red;\r\n}\r\n");

        $tracked = 'tests/fixtures/git-lf-fix/bad.css';

        try {
            [$code, $stdout, $stderr] = $this->runCli($root, ['git:lf:fix'], [
                'POLICY_GIT_TRACKED_FILES' => $tracked,
            ]);
            $this->assertSame(0, $code, $stdout . $stderr);
            $contents = (string) file_get_contents($path);
            $this->assertStringNotContainsString("\r", $contents);
            $this->assertStringContainsString('git.lf.fix.ok', $stdout);
            $this->assertStringContainsString($tracked, $stdout);
        } finally {
            @unlink($path);
            @rmdir($dir);
        }
    }

    /**
     * @param array<string, string|null> $env
     * @return array{0:int,1:string,2:string}
     */
    private function runCli(string $root, array $args, array $env): array
    {
        if (function_exists('proc_open')) {
            $baseEnv = $_ENV;
            foreach ($env as $key => $value) {
                if ($value === null) {
                    unset($baseEnv[$key]);
                } else {
                    $baseEnv[$key] = $value;
                }
            }
            $cmd = array_merge([PHP_BINARY, $root . '/tools/cli.php'], $args);
            $descriptors = [
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ];
            $process = proc_open($cmd, $descriptors, $pipes, $root, $baseEnv);
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
            foreach ($env as $key => $value) {
                if ($value === null) {
                    unset($_ENV[$key]);
                    putenv($key);
                } else {
                    $_ENV[$key] = $value;
                    putenv($key . '=' . $value);
                }
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
            foreach ($env as $key => $value) {
                $prev = $backup[$key] ?? null;
                if ($prev === null || $prev === '') {
                    unset($_ENV[$key]);
                    putenv($key);
                } else {
                    $_ENV[$key] = $prev;
                    putenv($key . '=' . $prev);
                }
            }

            return [(int) $code, $stdout, $stderr];
        }

        $this->markTestSkipped('CLI execution not available');
    }
}
