<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class MediaSha256DedupReportCommandTest extends TestCase
{
    public function testJsonOutputIncludesDuplicates(): void
    {
        $root = dirname(__DIR__, 2);
        $dbPath = $this->createTempDbPath();
        $this->createDatabase($dbPath);

        try {
            [$code, $stdout, $stderr] = $this->runCli($root, $dbPath, [
                'media:sha256:dedup:report',
                '--json',
                '--limit=10',
                '--with-paths',
            ]);
            $this->assertSame(0, $code, $stdout . $stderr);
            $data = json_decode($stdout, true);
            $this->assertIsArray($data);
            $this->assertArrayHasKey('meta', $data);
            $this->assertArrayHasKey('groups', $data);
            $this->assertSame(true, $data['meta']['disk_supported'] ?? null);
            $this->assertSame(false, $data['meta']['disk_filter_applied'] ?? null);
            $this->assertSame(10, $data['meta']['limit'] ?? null);
            $groups = $data['groups'];
            $this->assertNotEmpty($groups);
            $first = $groups[0];
            $this->assertSame('h1', $first['sha256']);
            $this->assertSame(2, $first['count']);
            $this->assertArrayHasKey('items', $first);
            $this->assertArrayHasKey('path', $first['items'][0]);
        } finally {
            @unlink($dbPath);
        }
    }

    public function testDiskFilterLimitsResults(): void
    {
        $root = dirname(__DIR__, 2);
        $dbPath = $this->createTempDbPath();
        $this->createDatabase($dbPath);

        try {
            [$code, $stdout, $stderr] = $this->runCli($root, $dbPath, [
                'media:sha256:dedup:report',
                '--json',
                '--disk=s3',
                '--limit=10',
            ]);
            $this->assertSame(0, $code, $stdout . $stderr);
            $data = json_decode($stdout, true);
            $this->assertIsArray($data);
            $this->assertArrayHasKey('meta', $data);
            $this->assertSame(true, $data['meta']['disk_supported'] ?? null);
            $this->assertSame(true, $data['meta']['disk_filter_applied'] ?? null);
            $groups = $data['groups'];
            $this->assertCount(1, $groups);
            $this->assertSame('h2', $groups[0]['sha256']);
        } finally {
            @unlink($dbPath);
        }
    }

    public function testDiskFilterWithoutDiskColumnWarns(): void
    {
        $root = dirname(__DIR__, 2);
        $dbPath = $this->createTempDbPath();
        $this->createDatabaseWithoutDisk($dbPath);

        try {
            [$code, $stdout, $stderr] = $this->runCli($root, $dbPath, [
                'media:sha256:dedup:report',
                '--json',
                '--disk=local',
                '--limit=10',
            ]);
            $this->assertSame(2, $code, $stdout . $stderr);
            $this->assertStringContainsString('Option --disk ignored', $stderr);
            $data = json_decode($stdout, true);
            $this->assertIsArray($data);
            $this->assertSame(false, $data['meta']['disk_supported'] ?? null);
            $this->assertSame(false, $data['meta']['disk_filter_applied'] ?? null);
        } finally {
            @unlink($dbPath);
        }
    }

    private function createTempDbPath(): string
    {
        $dbPath = tempnam(sys_get_temp_dir(), 'laas-media-');
        if ($dbPath === false) {
            $this->markTestSkipped('Temp DB could not be created');
        }

        return $dbPath;
    }

    private function createDatabase(string $dbPath): void
    {
        $pdo = new PDO('sqlite:' . $dbPath);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        $pdo->exec('CREATE TABLE media_files (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            uuid TEXT NOT NULL,
            disk_path TEXT NOT NULL,
            disk TEXT NOT NULL,
            original_name TEXT NOT NULL,
            mime_type TEXT NOT NULL,
            size_bytes INTEGER NOT NULL,
            sha256 TEXT NOT NULL,
            uploaded_by INTEGER NULL,
            created_at TEXT NOT NULL,
            is_public INTEGER NOT NULL DEFAULT 0,
            public_token TEXT NULL,
            status TEXT NOT NULL,
            quarantine_path TEXT NULL
        )');

        $stmt = $pdo->prepare(
            'INSERT INTO media_files (uuid, disk_path, disk, original_name, mime_type, size_bytes, sha256, uploaded_by, created_at, is_public, public_token, status, quarantine_path)
             VALUES (:uuid, :disk_path, :disk, :original_name, :mime_type, :size_bytes, :sha256, :uploaded_by, :created_at, :is_public, :public_token, :status, :quarantine_path)'
        );

        $now = '2026-01-01 00:00:00';
        $rows = [
            ['u1', 'uploads/2026/01/a.png', 'local', 'h1'],
            ['u2', 'uploads/2026/01/b.png', 'local', 'h1'],
            ['u3', 'uploads/2026/01/c.png', 's3', 'h2'],
            ['u4', 'uploads/2026/01/d.png', 's3', 'h2'],
            ['u5', 'uploads/2026/01/e.png', 'local', 'h3'],
        ];
        foreach ($rows as [$uuid, $path, $disk, $sha]) {
            $stmt->execute([
                'uuid' => $uuid,
                'disk_path' => $path,
                'disk' => $disk,
                'original_name' => basename($path),
                'mime_type' => 'image/png',
                'size_bytes' => 10,
                'sha256' => $sha,
                'uploaded_by' => null,
                'created_at' => $now,
                'is_public' => 0,
                'public_token' => null,
                'status' => 'ready',
                'quarantine_path' => null,
            ]);
        }
    }

    private function createDatabaseWithoutDisk(string $dbPath): void
    {
        $pdo = new PDO('sqlite:' . $dbPath);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        $pdo->exec('CREATE TABLE media_files (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            uuid TEXT NOT NULL,
            disk_path TEXT NOT NULL,
            original_name TEXT NOT NULL,
            mime_type TEXT NOT NULL,
            size_bytes INTEGER NOT NULL,
            sha256 TEXT NOT NULL,
            uploaded_by INTEGER NULL,
            created_at TEXT NOT NULL,
            status TEXT NOT NULL
        )');

        $stmt = $pdo->prepare(
            'INSERT INTO media_files (uuid, disk_path, original_name, mime_type, size_bytes, sha256, uploaded_by, created_at, status)
             VALUES (:uuid, :disk_path, :original_name, :mime_type, :size_bytes, :sha256, :uploaded_by, :created_at, :status)'
        );

        $stmt->execute([
            'uuid' => 'u1',
            'disk_path' => 'uploads/2026/01/a.png',
            'original_name' => 'a.png',
            'mime_type' => 'image/png',
            'size_bytes' => 10,
            'sha256' => 'h1',
            'uploaded_by' => null,
            'created_at' => '2026-01-01 00:00:00',
            'status' => 'ready',
        ]);
        $stmt->execute([
            'uuid' => 'u2',
            'disk_path' => 'uploads/2026/01/b.png',
            'original_name' => 'b.png',
            'mime_type' => 'image/png',
            'size_bytes' => 11,
            'sha256' => 'h1',
            'uploaded_by' => null,
            'created_at' => '2026-01-01 00:00:00',
            'status' => 'ready',
        ]);
    }

    /**
     * @return array{0:int,1:string,2:string}
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

            return [(int) $code, (string) $output, (string) $error];
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
            $stdoutPath = tempnam(sys_get_temp_dir(), 'laas-cli-out-');
            $stderrPath = tempnam(sys_get_temp_dir(), 'laas-cli-err-');
            if ($stdoutPath === false || $stderrPath === false) {
                $this->markTestSkipped('Temp files could not be created');
            }
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
