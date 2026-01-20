<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class HttpAssetsSmokeCommandTest extends TestCase
{
    public function testHttpAssetsSmokeOkWithFixture(): void
    {
        $root = dirname(__DIR__, 2);
        $base = 'http://example.test';
        $fixturePath = $this->writeFixture($root, $base, false);

        [$code, $output] = $this->runCli($root, ['assets:http:smoke', '--base=' . $base, '--fixture=' . $fixturePath]);

        $this->assertSame(0, $code, $output);
        $this->assertStringContainsString('assets.http.smoke.ok', $output);
    }

    public function testHttpAssetsSmokeFailsOnBadContentType(): void
    {
        $root = dirname(__DIR__, 2);
        $base = 'http://example.test';
        $fixturePath = $this->writeFixture($root, $base, true);

        [$code, $output] = $this->runCli($root, ['assets:http:smoke', '--base=' . $base, '--fixture=' . $fixturePath]);

        $this->assertSame(1, $code, $output);
        $this->assertStringContainsString('Unexpected Content-Type', $output);
    }

    private function writeFixture(string $root, string $base, bool $badContentType): string
    {
        $assets = require $root . '/config/assets.php';
        $htmxPath = $this->assetPath((string) ($assets['htmx_js'] ?? ''));
        $bootstrapJsPath = $this->assetPath((string) ($assets['bootstrap_js'] ?? ''));
        $bootstrapCssPath = $this->assetPath((string) ($assets['bootstrap_css'] ?? ''));
        $iconsCssPath = $this->assetPath((string) ($assets['bootstrap_icons_css'] ?? ''));
        $fontPath = $this->firstWoff2UrlPath($root, (string) ($assets['bootstrap_icons_css'] ?? ''));

        $fixture = [
            $base . $htmxPath => [
                'status' => 200,
                'content_type' => $badContentType ? 'text/plain' : 'application/javascript; charset=utf-8',
                'body' => '/*! htmx */(function(){})',
            ],
            $base . $bootstrapJsPath => [
                'status' => 200,
                'content_type' => 'application/javascript',
                'body' => '(()=>{ /* bootstrap */ })',
            ],
            $base . $bootstrapCssPath => [
                'status' => 200,
                'content_type' => 'text/css',
                'body' => '/* bootstrap */ .btn{}',
            ],
            $base . $iconsCssPath => [
                'status' => 200,
                'content_type' => 'text/css',
                'body' => '.bi{font-family:bootstrap-icons}',
            ],
            $base . $fontPath => [
                'status' => 200,
                'content_type' => 'font/woff2',
                'body' => '__FONT__',
            ],
        ];

        $fixturePath = $this->makeTempDir('assets-http') . '/fixture.php';
        $lines = ["<?php", "return ["];
        foreach ($fixture as $url => $meta) {
            $lines[] = '    ' . var_export($url, true) . ' => [';
            $lines[] = '        \'status\' => ' . (int) ($meta['status'] ?? 0) . ',';
            $lines[] = '        \'headers\' => [\'content-type\' => ' . var_export((string) ($meta['content_type'] ?? ''), true) . '],';
            if (($meta['body'] ?? '') === '__FONT__') {
                $lines[] = '        \'body\' => str_repeat("\\x00", 1101),';
            } else {
                $lines[] = '        \'body\' => ' . var_export((string) ($meta['body'] ?? ''), true) . ',';
            }
            $lines[] = '    ],';
        }
        $lines[] = '];';
        file_put_contents($fixturePath, implode("\n", $lines));

        return $fixturePath;
    }

    private function assetPath(string $asset): string
    {
        $parts = parse_url($asset);
        $path = is_array($parts) && isset($parts['path']) ? (string) $parts['path'] : $asset;
        if ($path === '') {
            return '';
        }
        if (!str_starts_with($path, '/')) {
            $path = '/' . ltrim($path, '/');
        }
        return $path;
    }

    private function firstWoff2UrlPath(string $root, string $cssAsset): string
    {
        $cssPath = $root . '/public' . $this->assetPath($cssAsset);
        if (!is_file($cssPath)) {
            $this->markTestSkipped('bootstrap-icons CSS missing');
        }
        $contents = file_get_contents($cssPath);
        if ($contents === false) {
            $this->markTestSkipped('bootstrap-icons CSS unreadable');
        }

        if (preg_match_all('/url\\(([^)]+)\\)/i', $contents, $matches) <= 0) {
            $this->markTestSkipped('No font URLs found');
        }

        $cssUrlPath = $this->assetPath($cssAsset);
        foreach ($matches[1] as $rawUrl) {
            if (!is_string($rawUrl)) {
                continue;
            }
            $clean = trim($rawUrl, " \t\n\r\0\x0B\"'");
            if ($clean === '' || str_starts_with($clean, 'data:') || str_starts_with($clean, '//')) {
                continue;
            }
            if (preg_match('/^[a-z][a-z0-9+.-]*:/i', $clean) === 1) {
                continue;
            }
            $pathPart = strtok($clean, '?#');
            if (strtolower(pathinfo((string) $pathPart, PATHINFO_EXTENSION)) !== 'woff2') {
                continue;
            }
            return $this->resolveUrlPath($cssUrlPath, $clean);
        }

        $this->markTestSkipped('No woff2 URL found');
    }

    private function resolveUrlPath(string $cssUrlPath, string $fontUrl): string
    {
        $parts = parse_url($fontUrl);
        $path = is_array($parts) ? (string) ($parts['path'] ?? $fontUrl) : $fontUrl;
        $query = is_array($parts) && isset($parts['query']) ? '?' . $parts['query'] : '';
        $fragment = is_array($parts) && isset($parts['fragment']) ? '#' . $parts['fragment'] : '';

        if ($path === '') {
            return '';
        }
        if (str_starts_with($path, '/')) {
            return $path . $query . $fragment;
        }
        $baseDir = rtrim(dirname($cssUrlPath), '/');
        $joined = $baseDir . '/' . ltrim($path, '/');
        $normalized = $this->normalizePath($joined);
        return $normalized . $query . $fragment;
    }

    private function normalizePath(string $path): string
    {
        $isAbsolute = str_starts_with($path, '/');
        $segments = explode('/', $path);
        $stack = [];
        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                array_pop($stack);
                continue;
            }
            $stack[] = $segment;
        }
        $out = implode('/', $stack);
        return $isAbsolute ? '/' . $out : $out;
    }

    private function makeTempDir(string $suffix): string
    {
        $root = rtrim(sys_get_temp_dir(), '/\\') . '/laas-assets-' . $suffix . '-' . uniqid('', true);
        mkdir($root, 0775, true);
        return $root;
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
