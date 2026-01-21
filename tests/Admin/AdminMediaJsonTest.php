<?php
declare(strict_types=1);

use Laas\Database\DatabaseManager;
use Laas\Http\Request;
use Laas\Modules\Media\Controller\AdminMediaController;
use Laas\View\View;
use PHPUnit\Framework\TestCase;
use Tests\Security\Support\SecurityTestHelper;
use Tests\Support\InMemorySession;

final class AdminMediaJsonTest extends TestCase
{
    public function testIndexReturnsJsonContract(): void
    {
        $pdo = $this->createBaseSchema();
        $this->seedMediaView($pdo, 1);
        $this->seedMediaFile($pdo, 1);

        $request = $this->makeRequest('GET', '/admin/media');
        $controller = $this->createController($pdo, $request);

        $response = $controller->index($request);

        $this->assertSame(200, $response->getStatus());
        $this->assertSame('application/json; charset=utf-8', $response->getHeader('Content-Type'));
        $payload = json_decode($response->getBody(), true);
        $this->assertSame('json', $payload['meta']['format'] ?? null);
        $this->assertSame('admin.media.index', $payload['meta']['route'] ?? null);
        $this->assertIsArray($payload['data']['items'] ?? null);
        $this->assertIsArray($payload['data']['counts'] ?? null);
        $items = $payload['data']['items'] ?? [];
        $this->assertNotEmpty($items);
        $first = $items[0] ?? [];
        $this->assertSame('report.pdf', $first['name'] ?? null);
        $this->assertSame('application/pdf', $first['mime'] ?? null);
    }

    private function createBaseSchema(): \PDO
    {
        $pdo = SecurityTestHelper::createSqlitePdo();
        SecurityTestHelper::seedRbacTables($pdo);
        SecurityTestHelper::seedMediaTable($pdo);
        return $pdo;
    }

    private function seedMediaView(\PDO $pdo, int $userId): void
    {
        SecurityTestHelper::insertUser($pdo, $userId, 'admin', 'hash');
        SecurityTestHelper::insertRole($pdo, 1, 'admin');
        SecurityTestHelper::insertPermission($pdo, 1, 'media.view');
        SecurityTestHelper::assignRole($pdo, $userId, 1);
        SecurityTestHelper::grantPermission($pdo, 1, 1);
    }

    private function seedMediaFile(\PDO $pdo, int $id): void
    {
        $stmt = $pdo->prepare('INSERT INTO media_files (id, uuid, disk_path, original_name, mime_type, size_bytes, sha256, uploaded_by, created_at, is_public, public_token) VALUES (:id, :uuid, :disk_path, :original_name, :mime_type, :size_bytes, :sha256, :uploaded_by, :created_at, :is_public, :public_token)');
        $stmt->execute([
            'id' => $id,
            'uuid' => 'uuid-test',
            'disk_path' => 'uploads/2026/01/uuid-test.pdf',
            'original_name' => 'report.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 1234,
            'sha256' => 'hash-test',
            'uploaded_by' => 1,
            'created_at' => '2026-01-01 00:00:00',
            'is_public' => 0,
            'public_token' => null,
        ]);
    }

    private function makeRequest(string $method, string $path): Request
    {
        $request = new Request($method, $path, [], [], ['accept' => 'application/json'], '');
        $session = new InMemorySession();
        $session->start();
        $session->set('user_id', 1);
        $request->setSession($session);
        return $request;
    }

    private function createController(\PDO $pdo, Request $request): AdminMediaController
    {
        $db = SecurityTestHelper::dbManagerFromPdo($pdo);
        $view = $this->createView($db, $request);
        $container = SecurityTestHelper::createContainer($db);
        return new AdminMediaController($view, null, null, $container);
    }

    private function createView(DatabaseManager $db, Request $request): View
    {
        return SecurityTestHelper::createView($db, $request, 'admin');
    }
}
