<?php
declare(strict_types=1);

require_once __DIR__ . '/Support/SecurityTestHelper.php';
require_once __DIR__ . '/Support/StorageSpy.php';

use Laas\Database\DatabaseManager;
use Laas\Http\Middleware\ReadOnlyMiddleware;
use Laas\Http\Request;
use Laas\Http\Response;
use Laas\I18n\Translator;
use Laas\Modules\Media\Service\StorageService;
use Laas\Support\BackupManager;
use Laas\Support\ConfigSanityChecker;
use Laas\Support\HealthService;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\Security\Support\SecurityTestHelper;
use Tests\Security\Support\StorageSpy;

#[Group('security')]
final class OpsSafetySecurityTest extends TestCase
{
    private string $rootPath;

    protected function setUp(): void
    {
        $this->rootPath = SecurityTestHelper::rootPath();
    }

    public function testReadOnlyBlocksMutations(): void
    {
        $translator = new Translator($this->rootPath, 'default', 'en');
        $middleware = new ReadOnlyMiddleware(true, $translator, null);

        $request = new Request('POST', '/admin/media/upload', [], [], [], '');
        $response = $middleware->process($request, static fn(): Response => new Response('ok', 200));

        $this->assertSame(503, $response->getStatus());
    }

    public function testReadOnlyAllowsHealth(): void
    {
        $translator = new Translator($this->rootPath, 'default', 'en');
        $middleware = new ReadOnlyMiddleware(true, $translator, null);

        $request = new Request('POST', '/health', [], [], [], '');
        $response = $middleware->process($request, static fn(): Response => new Response('ok', 200));

        $this->assertSame(200, $response->getStatus());
    }

    public function testHealthSafeModeDoesNotWriteStorage(): void
    {
        $spy = new StorageSpy();
        $storage = new StorageService($this->rootPath, $spy);
        $checker = new ConfigSanityChecker();
        $config = [
            'storage' => [
                'default' => 'local',
                'default_raw' => 'local',
                'disks' => ['s3' => []],
            ],
            'media' => [
                'max_bytes' => 1024,
                'allowed_mime' => ['image/png'],
                'max_bytes_by_mime' => [],
            ],
        ];

        $service = new HealthService(
            $this->rootPath,
            static fn(): bool => true,
            $storage,
            $checker,
            $config,
            false
        );

        $result = $service->check();
        $this->assertTrue($result['ok']);
        $this->assertSame([], $spy->putContents);
        $this->assertNotEmpty($spy->exists);
    }

    public function testBackupInspectValidatesChecksums(): void
    {
        [$root, $db, $storage] = $this->createEnv();
        $manager = $this->manager($root, $db, $storage, 'dev');
        $result = $manager->create(['db_driver' => 'pdo']);

        $inspect = $manager->inspect($result['file']);
        $this->assertTrue($inspect['ok']);
        $this->assertTrue($inspect['checks']['manifest']);
        $this->assertTrue($inspect['checks']['db']);
        $this->assertTrue($inspect['checks']['entries']);
    }

    public function testRestoreForbiddenInProdWithoutForce(): void
    {
        [$root, $db, $storage] = $this->createEnv();
        $manager = $this->manager($root, $db, $storage, 'prod');
        $result = $manager->create(['db_driver' => 'pdo']);

        $restore = $manager->restore($result['file'], [
            'confirm1' => 'RESTORE',
            'confirm2' => basename($result['file']),
        ]);

        $this->assertFalse($restore['ok']);
        $this->assertSame('forbidden_in_prod', $restore['error']);
    }

    public function testRestoreLockPreventsParallelRun(): void
    {
        [$root, $db, $storage] = $this->createEnv();
        $manager = $this->manager($root, $db, $storage, 'dev');
        $result = $manager->create(['db_driver' => 'pdo']);

        $lockPath = $root . '/storage/backups/.restore.lock';
        @mkdir($root . '/storage/backups', 0775, true);
        $handle = fopen($lockPath, 'c+');
        $this->assertNotFalse($handle);
        $this->assertTrue(flock($handle, LOCK_EX | LOCK_NB));

        $restore = $manager->restore($result['file'], [
            'confirm1' => 'RESTORE',
            'confirm2' => basename($result['file']),
        ]);

        $this->assertFalse($restore['ok']);
        $this->assertSame('locked', $restore['error']);
        flock($handle, LOCK_UN);
        fclose($handle);
    }

    public function testDryRunDoesNotModifyState(): void
    {
        [$root, $db, $storage] = $this->createEnv();
        $manager = $this->manager($root, $db, $storage, 'dev');
        $result = $manager->create(['db_driver' => 'pdo']);

        $db->pdo()->exec("INSERT INTO media_files (uuid, disk_path, original_name, mime_type, size_bytes, sha256, uploaded_by, created_at) VALUES ('u2','uploads/2026/01/after.jpg','after.jpg','image/jpeg',4,'h2',NULL,'2026-01-01 00:00:00')");
        file_put_contents($root . '/storage/uploads/2026/01/after.jpg', 'after');

        $restore = $manager->restore($result['file'], [
            'dry_run' => true,
            'confirm1' => 'RESTORE',
            'confirm2' => basename($result['file']),
        ]);

        $this->assertTrue($restore['ok']);
        $count = (int) $db->pdo()->query('SELECT COUNT(*) AS c FROM media_files')->fetchColumn();
        $this->assertSame(2, $count);
        $this->assertSame('after', file_get_contents($root . '/storage/uploads/2026/01/after.jpg'));
    }

    private function createEnv(): array
    {
        $root = sys_get_temp_dir() . '/laas_backup_sec_' . bin2hex(random_bytes(4));
        @mkdir($root . '/storage/uploads/2026/01', 0775, true);
        @mkdir($root . '/storage/backups', 0775, true);

        $db = $this->createDatabase();
        $storage = new StorageService($root);

        $mediaPath = $root . '/storage/uploads/2026/01/file.jpg';
        file_put_contents($mediaPath, 'file');
        $db->pdo()->exec("INSERT INTO media_files (uuid, disk_path, original_name, mime_type, size_bytes, sha256, uploaded_by, created_at) VALUES ('u1','uploads/2026/01/file.jpg','file.jpg','image/jpeg',4,'h1',NULL,'2026-01-01 00:00:00')");

        return [$root, $db, $storage];
    }

    private function manager(string $root, DatabaseManager $db, StorageService $storage, string $env): BackupManager
    {
        return new BackupManager($root, $db, $storage, [
            'version' => 'v2.2.5',
            'env' => $env,
        ], [
            'default' => 'local',
        ]);
    }

    private function createDatabase(): DatabaseManager
    {
        $pdo = SecurityTestHelper::createSqlitePdo();
        $pdo->exec('CREATE TABLE media_files (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            uuid TEXT NOT NULL,
            disk_path TEXT NOT NULL,
            original_name TEXT NOT NULL,
            mime_type TEXT NOT NULL,
            size_bytes INTEGER NOT NULL,
            sha256 TEXT NULL,
            uploaded_by INTEGER NULL,
            created_at TEXT NOT NULL
        )');

        return SecurityTestHelper::dbManagerFromPdo($pdo);
    }
}
