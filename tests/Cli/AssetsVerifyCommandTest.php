<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class AssetsVerifyCommandTest extends TestCase
{
    public function testAssetsVerifyPassesForRepoAssets(): void
    {
        $root = dirname(__DIR__, 2);
        [$code, $output] = $this->runCli($root, ['assets:verify']);

        $this->assertSame(0, $code, $output);
        $this->assertStringContainsString('assets.verify.ok', $output);
    }

    public function testAssetsVerifyFailsOnPlaceholder(): void
    {
        $root = dirname(__DIR__, 2);
        $fixtureRoot = $this->makeTempDir('assets-verify');
        $this->writeAssetsConfig($fixtureRoot);

        $assetsRoot = $fixtureRoot . '/public/assets/vendor';
        $this->writeSizedFile($assetsRoot . '/htmx/0.0.0/htmx.min.js', 12000, 'vendor placeholder');
        $this->writeSizedFile($assetsRoot . '/bootstrap/0.0.0/bootstrap.min.css', 6000, '/* ok */');
        $this->writeSizedFile($assetsRoot . '/bootstrap/0.0.0/bootstrap.bundle.min.js', 12000, '/* ok */');
        $iconsCss = '@font-face{src:url("./fonts/bootstrap-icons.woff2") format("woff2"),url("./fonts/bootstrap-icons.woff") format("woff")}.bi{font-family:bootstrap-icons}';
        $this->writeSizedFile($assetsRoot . '/bootstrap-icons/0.0.0/bootstrap-icons.min.css', 6000, $iconsCss);
        $this->writeSizedFile($assetsRoot . '/bootstrap-icons/0.0.0/fonts/bootstrap-icons.woff2', 6000, 'ok');
        $this->writeSizedFile($assetsRoot . '/bootstrap-icons/0.0.0/fonts/bootstrap-icons.woff', 6000, 'ok');

        [$code, $output] = $this->runCli($root, ['assets:verify', '--root=' . $fixtureRoot]);

        $this->assertSame(1, $code, $output);
        $this->assertStringContainsString('vendor placeholder', $output);
    }

    private function makeTempDir(string $suffix): string
    {
        $root = rtrim(sys_get_temp_dir(), '/\\') . '/laas-assets-' . $suffix . '-' . uniqid('', true);
        mkdir($root, 0775, true);
        return $root;
    }

    private function writeAssetsConfig(string $root): void
    {
        $configDir = $root . '/config';
        mkdir($configDir, 0775, true);

        $config = [
            'htmx_js' => '/assets/vendor/htmx/0.0.0/htmx.min.js',
            'bootstrap_css' => '/assets/vendor/bootstrap/0.0.0/bootstrap.min.css',
            'bootstrap_js' => '/assets/vendor/bootstrap/0.0.0/bootstrap.bundle.min.js',
            'bootstrap_icons_css' => '/assets/vendor/bootstrap-icons/0.0.0/bootstrap-icons.min.css',
        ];

        $contents = "<?php\n" . "declare(strict_types=1);\n\nreturn " . var_export($config, true) . ";\n";
        file_put_contents($configDir . '/assets.php', $contents);
    }

    private function writeSizedFile(string $path, int $size, string $prefix): void
    {
        if (strlen($prefix) > $size) {
            $this->markTestSkipped('Prefix exceeds size for test asset');
        }
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $content = $prefix . str_repeat('x', $size - strlen($prefix));
        file_put_contents($path, $content);
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
