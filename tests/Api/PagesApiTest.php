<?php
declare(strict_types=1);

use Laas\Database\DatabaseManager;
use Laas\Http\ErrorCode;
use Laas\Http\Request;
use Laas\Modules\Api\Controller\PagesController;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('api')]
final class PagesApiTest extends TestCase
{
    public function testListPublishedOnly(): void
    {
        $db = $this->createDb();
        $service = new \Laas\Domain\Pages\PagesService($db);
        $controller = new PagesController(null, $service);

        $request = new Request('GET', '/api/v1/pages', [], [], [], '');
        $response = $controller->index($request);

        $payload = json_decode($response->getBody(), true);
        $this->assertTrue($payload['ok']);
        $this->assertSame(1, count($payload['data']));
        $this->assertSame('published', $payload['data'][0]['status']);
        $this->assertSame(1, (int) $payload['meta']['total']);
    }

    public function testBySlug(): void
    {
        $db = $this->createDb();
        $service = new \Laas\Domain\Pages\PagesService($db);
        $controller = new PagesController(null, $service);

        $request = new Request('GET', '/api/v1/pages/by-slug/hello', [], [], [], '');
        $response = $controller->bySlug($request, ['slug' => 'hello']);

        $payload = json_decode($response->getBody(), true);
        $this->assertTrue($payload['ok']);
        $this->assertSame('hello', $payload['data']['slug']);
    }

    public function testPaginationClamp(): void
    {
        $db = $this->createDb();
        $service = new \Laas\Domain\Pages\PagesService($db);
        $controller = new PagesController(null, $service);

        $request = new Request('GET', '/api/v1/pages', ['per_page' => '999'], [], [], '');
        $response = $controller->index($request);

        $payload = json_decode($response->getBody(), true);
        $this->assertTrue($payload['ok']);
        $this->assertSame(50, (int) $payload['meta']['per_page']);
    }

    public function testQueryTooShort(): void
    {
        $db = $this->createDb();
        $service = new \Laas\Domain\Pages\PagesService($db);
        $controller = new PagesController(null, $service);

        $request = new Request('GET', '/api/v1/pages', ['q' => 'a'], [], [], '');
        $response = $controller->index($request);

        $payload = json_decode($response->getBody(), true);
        $this->assertSame(ErrorCode::VALIDATION_FAILED, $payload['error']['code'] ?? null);
        $this->assertSame(422, $response->getStatus());
    }

    private function createDb(): DatabaseManager
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        $pdo->exec('CREATE TABLE pages (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            title TEXT,
            slug TEXT UNIQUE,
            content TEXT,
            status TEXT,
            created_at TEXT,
            updated_at TEXT
        )');

        $pdo->exec("INSERT INTO pages (title, slug, content, status, created_at, updated_at) VALUES
            ('Hello', 'hello', 'Body', 'published', '2026-01-01', '2026-01-01'),
            ('Draft', 'draft', 'Body', 'draft', '2026-01-01', '2026-01-01')");

        $db = new DatabaseManager(['driver' => 'mysql']);
        $ref = new ReflectionProperty($db, 'pdo');
        $ref->setAccessible(true);
        $ref->setValue($db, $pdo);

        return $db;
    }
}
