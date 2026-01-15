<?php
declare(strict_types=1);

namespace {
    use Laas\Database\DatabaseManager;
    use Laas\Modules\Media\Repository\MediaRepository;
    use Laas\Modules\Media\Service\MediaDedupeWaitPolicy;
    use Laas\Modules\Media\Service\MediaDedupeWaiter;
    use Laas\Modules\Media\Service\MediaUploadPendingException;
    use PHPUnit\Framework\TestCase;

    final class MediaUploadDedupeWaitTest extends TestCase
    {
        public function testWaitsUntilReady(): void
        {
            $db = $this->createDatabase();
            $repo = new MediaRepository($db);
            $id = $this->insertMedia($db->pdo(), 'hash-wait', 'uploading');

            $waiter = new MediaDedupeWaiter($repo);
            $policy = new MediaDedupeWaitPolicy(1000, 10, 50, 0);

            $nowMs = 0;
            $sleeps = 0;
            $sleep = function (int $ms) use (&$nowMs, &$sleeps, $db, $id): void {
                $sleeps++;
                $nowMs += $ms;
                if ($sleeps === 2) {
                    $stmt = $db->pdo()->prepare('UPDATE media_files SET status = :status WHERE id = :id');
                    $stmt->execute([
                        'status' => 'ready',
                        'id' => $id,
                    ]);
                }
            };
            $now = static function () use (&$nowMs): int {
                return $nowMs;
            };

            $row = $waiter->waitForReadyBySha256('hash-wait', $policy, $sleep, $now);
            $this->assertSame($id, (int) ($row['id'] ?? 0));
            $this->assertSame('ready', (string) ($row['status'] ?? ''));
        }

        public function testTimeoutThrowsPending(): void
        {
            $db = $this->createDatabase();
            $repo = new MediaRepository($db);
            $this->insertMedia($db->pdo(), 'hash-pending', 'uploading');

            $waiter = new MediaDedupeWaiter($repo);
            $policy = new MediaDedupeWaitPolicy(50, 10, 20, 0);

            $nowMs = 0;
            $sleep = function (int $ms) use (&$nowMs): void {
                $nowMs += $ms;
            };
            $now = static function () use (&$nowMs): int {
                return $nowMs;
            };

            $this->expectException(MediaUploadPendingException::class);
            $waiter->waitForReadyBySha256('hash-pending', $policy, $sleep, $now);
        }

        public function testBackoffGrowsAndCaps(): void
        {
            $db = $this->createDatabase();
            $repo = new MediaRepository($db);
            $this->insertMedia($db->pdo(), 'hash-backoff', 'uploading');

            $waiter = new MediaDedupeWaiter($repo);
            $policy = new MediaDedupeWaitPolicy(100, 10, 50, 0);

            $nowMs = 0;
            $delays = [];
            $sleep = function (int $ms) use (&$nowMs, &$delays): void {
                $delays[] = $ms;
                $nowMs += $ms;
            };
            $now = static function () use (&$nowMs): int {
                return $nowMs;
            };

            try {
                $waiter->waitForReadyBySha256('hash-backoff', $policy, $sleep, $now);
                $this->fail('Expected MediaUploadPendingException.');
            } catch (MediaUploadPendingException) {
                $this->assertSame([10, 20, 40, 50], $delays);
            }
        }

        private function createDatabase(): DatabaseManager
        {
            $pdo = new \PDO('sqlite::memory:');
            $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
            $pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);

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
                is_public INTEGER NOT NULL DEFAULT 0,
                public_token TEXT NULL,
                status TEXT NOT NULL,
                quarantine_path TEXT NULL
            )');
            $pdo->exec('CREATE UNIQUE INDEX idx_media_files_sha256 ON media_files(sha256)');

            $db = new DatabaseManager(['driver' => 'mysql']);
            $ref = new \ReflectionProperty($db, 'pdo');
            $ref->setAccessible(true);
            $ref->setValue($db, $pdo);

            return $db;
        }

        private function insertMedia(\PDO $pdo, string $sha256, string $status): int
        {
            $stmt = $pdo->prepare(
                'INSERT INTO media_files (uuid, disk_path, original_name, mime_type, size_bytes, sha256, uploaded_by, created_at, is_public, public_token, status, quarantine_path)
                 VALUES (:uuid, :disk_path, :original_name, :mime_type, :size_bytes, :sha256, :uploaded_by, :created_at, :is_public, :public_token, :status, :quarantine_path)'
            );
            $stmt->execute([
                'uuid' => 'u-' . $sha256,
                'disk_path' => 'uploads/2026/01/' . $sha256 . '.png',
                'original_name' => $sha256 . '.png',
                'mime_type' => 'image/png',
                'size_bytes' => 4,
                'sha256' => $sha256,
                'uploaded_by' => null,
                'created_at' => date('Y-m-d H:i:s'),
                'is_public' => 0,
                'public_token' => null,
                'status' => $status,
                'quarantine_path' => null,
            ]);

            return (int) $pdo->lastInsertId();
        }
    }
}
