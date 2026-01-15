<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class ContentSanitizePagesCommandTest extends TestCase
{
    public function testDryRunDoesNotWrite(): void
    {
        $root = dirname(__DIR__, 2);
        $dbPath = $this->createTempDbPath();
        $pdo = $this->createDatabase($dbPath, [
            '<script>alert(1)</script><p>Body</p>',
        ]);

        $before = (string) $pdo->query('SELECT content FROM pages WHERE id = 1')->fetchColumn();

        try {
            [$code, $output] = $this->runCli($root, $dbPath, ['content:sanitize-pages', '--dry-run']);
            $this->assertSame(0, $code, $output);
            $this->assertStringContainsString('scanned=1', $output);
            $this->assertStringContainsString('changed=1', $output);
            $this->assertStringContainsString('updated=0', $output);
            $this->assertStringContainsString('mode=dry-run', $output);

            $after = (string) $pdo->query('SELECT content FROM pages WHERE id = 1')->fetchColumn();
            $this->assertSame($before, $after);
        } finally {
            @unlink($dbPath);
        }
    }

    public function testApplyWithoutYesIsRefused(): void
    {
        $root = dirname(__DIR__, 2);
        $dbPath = $this->createTempDbPath();
        $pdo = $this->createDatabase($dbPath, [
            '<script>alert(1)</script><p>Body</p>',
        ]);

        $before = (string) $pdo->query('SELECT content FROM pages WHERE id = 1')->fetchColumn();

        try {
            [$code, $output] = $this->runCli($root, $dbPath, ['content:sanitize-pages']);
            $this->assertSame(2, $code, $output);
            $this->assertStringContainsString('without --yes', $output);
            $this->assertStringContainsString('updated=0', $output);

            $after = (string) $pdo->query('SELECT content FROM pages WHERE id = 1')->fetchColumn();
            $this->assertSame($before, $after);
        } finally {
            @unlink($dbPath);
        }
    }

    public function testOffsetAndLimitAffectSelection(): void
    {
        $root = dirname(__DIR__, 2);
        $dbPath = $this->createTempDbPath();
        $this->createDatabase($dbPath, [
            '<script>alert(1)</script><p>One</p>',
            '<script>alert(1)</script><p>Two</p>',
            '<script>alert(1)</script><p>Three</p>',
            '<script>alert(1)</script><p>Four</p>',
            '<script>alert(1)</script><p>Five</p>',
        ]);

        try {
            [$code, $output] = $this->runCli($root, $dbPath, [
                'content:sanitize-pages',
                '--dry-run',
                '--limit=2',
                '--offset=2',
            ]);
            $this->assertSame(0, $code, $output);
            $this->assertStringContainsString('scanned=2', $output);
            $this->assertStringContainsString('updated=0', $output);
            $this->assertStringContainsString('mode=dry-run', $output);
        } finally {
            @unlink($dbPath);
        }
    }

    private function createTempDbPath(): string
    {
        $dbPath = tempnam(sys_get_temp_dir(), 'laas-pages-');
        if ($dbPath === false) {
            $this->markTestSkipped('Temp DB could not be created');
        }

        return $dbPath;
    }

    private function createDatabase(string $dbPath, array $contents): PDO
    {
        $pdo = new PDO('sqlite:' . $dbPath);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE pages (id INTEGER PRIMARY KEY AUTOINCREMENT, title VARCHAR(255) NOT NULL, slug VARCHAR(255) NOT NULL UNIQUE, content TEXT NOT NULL, status VARCHAR(20) NOT NULL DEFAULT \'draft\', created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL)');
        $stmt = $pdo->prepare('INSERT INTO pages (title, slug, content, status, created_at, updated_at) VALUES (:title, :slug, :content, :status, :created_at, :updated_at)');
        $index = 1;
        foreach ($contents as $content) {
            $stmt->execute([
                'title' => 'Page ' . $index,
                'slug' => 'page-' . $index,
                'content' => (string) $content,
                'status' => 'published',
                'created_at' => '2026-01-01 00:00:00',
                'updated_at' => '2026-01-01 00:00:00',
            ]);
            $index++;
        }

        return $pdo;
    }

    /**
     * @return array{0:int,1:string}
     */
    private function runCli(string $root, string $dbPath, array $args): array
    {
        if (function_exists('proc_open')) {
            $env = $_ENV;
            $env['DB_DRIVER'] = 'sqlite';
            $env['DB_DATABASE'] = $dbPath;
            $env['DB_NAME'] = $dbPath;
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
            $_ENV['DB_DATABASE'] = $dbPath;
            $_ENV['DB_NAME'] = $dbPath;
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
