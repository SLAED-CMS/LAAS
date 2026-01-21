<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class AdminSmokeCommandTest extends TestCase
{
    public function testSmokeOkWhenFlagsEnabled(): void
    {
        $root = dirname(__DIR__, 2);
        $fixturePath = $this->writeFixture($root, true);

        [$code, $output] = $this->runCli($root, [
            'admin:smoke',
            '--fixture=' . $fixturePath,
        ], [
            'APP_ENV' => 'dev',
            'APP_DEBUG' => 'true',
        ]);

        $this->assertSame(0, $code, $output);
        $this->assertStringContainsString('admin.smoke.ok admin.status', $output);
        $this->assertStringContainsString('admin.smoke.ok admin.themes.status', $output);
        $this->assertStringContainsString('admin.smoke.ok admin.headless.status', $output);
    }

    public function testSmokeOkWhenFlagsDisabled(): void
    {
        $root = dirname(__DIR__, 2);
        $fixturePath = $this->writeFixture($root, false);

        [$code, $output] = $this->runCli($root, [
            'admin:smoke',
            '--fixture=' . $fixturePath,
        ], [
            'APP_ENV' => 'prod',
            'APP_DEBUG' => 'false',
        ]);

        $this->assertSame(0, $code, $output);
        $this->assertStringContainsString('admin.smoke.ok admin.themes.status', $output);
        $this->assertStringContainsString('admin.smoke.ok admin.headless.status', $output);
    }

    private function writeFixture(string $root, bool $enabled): string
    {
        $responses = [
            '/admin' => [
                'status' => 200,
                'headers' => [
                    'Content-Type' => 'text/html; charset=utf-8',
                ],
                'body' => $enabled ? '<a data-palette-open="1">Palette</a>' : '<div>Admin</div>',
            ],
            '/admin/modules' => [
                'status' => 200,
                'headers' => [
                    'Content-Type' => 'text/html; charset=utf-8',
                ],
                'body' => '<h1>Modules</h1><tr id="module-core"></tr>',
            ],
            '/admin/pages/1/edit' => [
                'status' => 200,
                'headers' => [
                    'Content-Type' => 'text/html; charset=utf-8',
                ],
                'body' => $enabled ? '<div>Blocks (JSON)</div>' : '<div>Pages</div>',
            ],
            '/admin/themes' => [
                'status' => $enabled ? 200 : 404,
                'headers' => [
                    'Content-Type' => 'text/html; charset=utf-8',
                    'Cache-Control' => $enabled ? 'no-store' : '',
                ],
                'body' => $enabled ? '<form data-theme-validate="1"></form>' : '<div>Not Found</div>',
            ],
            '/admin/headless-playground' => [
                'status' => $enabled ? 200 : 404,
                'headers' => [
                    'Content-Type' => 'text/html; charset=utf-8',
                    'Cache-Control' => $enabled ? 'no-store' : '',
                ],
                'body' => $enabled
                    ? '<form data-headless-form="1"></form><div data-headless-result="1"></div>'
                    : '<div>Not Found</div>',
            ],
        ];

        $fixturePath = $this->makeTempDir('admin-smoke') . '/fixture.php';
        $lines = ["<?php", "return ["];
        foreach ($responses as $path => $response) {
            $lines[] = '    ' . var_export($path, true) . ' => [';
            $lines[] = '        \'status\' => ' . (int) ($response['status'] ?? 0) . ',';
            $lines[] = '        \'headers\' => ' . var_export($response['headers'] ?? [], true) . ',';
            $lines[] = '        \'body\' => ' . var_export((string) ($response['body'] ?? ''), true) . ',';
            $lines[] = '    ],';
        }
        $lines[] = '];';
        file_put_contents($fixturePath, implode("\n", $lines));

        return $fixturePath;
    }

    private function makeTempDir(string $suffix): string
    {
        $root = rtrim(sys_get_temp_dir(), '/\\') . '/laas-admin-smoke-' . $suffix . '-' . uniqid('', true);
        mkdir($root, 0775, true);
        return $root;
    }

    /**
     * @param array<int, string> $args
     * @param array<string, string> $envOverrides
     * @return array{0:int,1:string}
     */
    private function runCli(string $root, array $args, array $envOverrides): array
    {
        if (function_exists('proc_open')) {
            $env = $_ENV;
            foreach ($envOverrides as $key => $value) {
                $env[$key] = $value;
            }
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
            foreach ($envOverrides as $key => $value) {
                $_ENV[$key] = $value;
                putenv($key . '=' . $value);
            }

            $parts = array_map('escapeshellarg', $args);
            $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/tools/cli.php') . ' ' . implode(' ', $parts) . ' 2>&1';
            $output = [];
            $code = 0;
            exec($cmd, $output, $code);

            $_ENV = $backup;
            foreach ($envOverrides as $key => $value) {
                $prev = $backup[$key] ?? null;
                if ($prev === null || $prev === '') {
                    putenv($key);
                } else {
                    putenv($key . '=' . $prev);
                }
            }

            return [(int) $code, implode("\n", $output)];
        }

        $this->markTestSkipped('CLI execution not available');
    }
}
