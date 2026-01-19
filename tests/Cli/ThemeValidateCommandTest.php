<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class ThemeValidateCommandTest extends TestCase
{
    public function testThemesValidatePasses(): void
    {
        $root = dirname(__DIR__, 2);
        $fixtureRoot = $this->makeTempDir('themes-validate');
        $themePath = $fixtureRoot . '/site';
        mkdir($themePath . '/layouts', 0775, true);
        mkdir($themePath . '/partials', 0775, true);
        file_put_contents($themePath . '/layouts/base.html', '<html></html>');
        file_put_contents($themePath . '/partials/header.html', '<div></div>');
        file_put_contents($themePath . '/theme.json', json_encode([
            'name' => 'site',
            'version' => '1.0.0',
            'api' => 'v2',
            'capabilities' => ['toasts'],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $snapshotPath = $this->writeSnapshot($fixtureRoot, 'site');

        [$code, $output] = $this->runCli($root, [
            'themes:validate',
            'site',
            '--themes-root=' . $fixtureRoot,
            '--snapshot=' . $snapshotPath,
        ]);

        $this->assertSame(0, $code, $output);
        $this->assertStringContainsString('Theme site: OK', $output);
    }

    private function makeTempDir(string $suffix): string
    {
        $root = rtrim(sys_get_temp_dir(), '/\\') . '/laas-theme-' . $suffix . '-' . uniqid('', true);
        mkdir($root, 0775, true);
        return $root;
    }

    private function writeSnapshot(string $root, string $themeName): string
    {
        $themePath = $root . '/' . $themeName . '/theme.json';
        $hash = hash_file('sha256', $themePath);
        $snapshot = [
            'version' => 1,
            'generated_at' => '2026-01-19T00:00:00Z',
            'themes' => [
                $themeName => [
                    'sha256' => $hash,
                    'path' => 'themes/' . $themeName . '/theme.json',
                ],
            ],
        ];
        $snapshotPath = $root . '/config/theme_snapshot.php';
        mkdir($root . '/config', 0775, true);
        file_put_contents($snapshotPath, "<?php\n" . "declare(strict_types=1);\n\nreturn " . var_export($snapshot, true) . ";\n");
        return $snapshotPath;
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
