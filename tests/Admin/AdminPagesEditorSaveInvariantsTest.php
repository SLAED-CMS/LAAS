<?php
declare(strict_types=1);

use Laas\Database\DatabaseManager;
use Laas\Domain\Pages\PagesReadServiceInterface;
use Laas\Domain\Pages\PagesWriteServiceInterface;
use Laas\Domain\Pages\Dto\PageSummary;
use Laas\Domain\Pages\Dto\PageView;
use Laas\Domain\Rbac\RbacService;
use Laas\Http\Request;
use Laas\Modules\Pages\Controller\AdminPagesController;
use Laas\View\View;
use PHPUnit\Framework\TestCase;
use Tests\Security\Support\SecurityTestHelper;
use Tests\Support\InMemorySession;
use PDO;

final class AdminPagesEditorSaveInvariantsTest extends TestCase
{
    private ?string $previousDebug = null;
    private ?string $previousAssetBase = null;

    protected function setUp(): void
    {
        $this->previousDebug = $_ENV['APP_DEBUG'] ?? null;
        $this->previousAssetBase = $_ENV['ASSET_BASE'] ?? null;
        $_ENV['APP_DEBUG'] = 'true';
    }

    protected function tearDown(): void
    {
        if ($this->previousDebug === null) {
            unset($_ENV['APP_DEBUG']);
        } else {
            $_ENV['APP_DEBUG'] = $this->previousDebug;
        }
        if ($this->previousAssetBase === null) {
            unset($_ENV['ASSET_BASE']);
        } else {
            $_ENV['ASSET_BASE'] = $this->previousAssetBase;
        }
    }

    public function testToastUiMissingForcesHtmlAndDoesNotAddFormat(): void
    {
        $_ENV['ASSET_BASE'] = '/_assets_missing';
        $db = $this->createDatabase();
        $this->seedEditor($db->pdo(), 1);

        $request = $this->makeRequest([
            'title' => 'Missing assets',
            'slug' => 'missing-assets',
            'content' => '# Title',
            'content_format' => 'markdown',
            'editor_id' => 'toastui',
            'status' => 'draft',
            'blocks_json' => '[{"type":"rich_text","data":{"html":"<p>Block</p>","format":"markdown"}}]',
        ]);
        $view = SecurityTestHelper::createView($db, $request, 'admin');
        $read = $this->readService();
        $write = new class implements PagesWriteServiceInterface {
            /** @var array<string, mixed> */
            public array $created = [];
            /** @var array<int, array{page_id: int, blocks: array, created_by: ?int}> */
            public array $revisions = [];

            public function create(array $data): array
            {
                $this->created = $data;
                return ['id' => 1];
            }

            public function update(int $id, array $data): void
            {
            }

            public function updateStatus(int $id, string $status): void
            {
            }

            public function delete(int $id): void
            {
            }

            public function createRevision(int $pageId, array $blocks, ?int $createdBy): int
            {
                $this->revisions[] = [
                    'page_id' => $pageId,
                    'blocks' => $blocks,
                    'created_by' => $createdBy,
                ];
                return 1;
            }

            public function deleteRevisionsByPageId(int $pageId): void
            {
            }
        };
        $rbac = new RbacService($db);
        $controller = new AdminPagesController($view, $read, $write, null, $rbac);

        $response = $controller->save($request);

        $this->assertSame(303, $response->getStatus());
        $this->assertSame('html', $write->created['content_format'] ?? null);
        $this->assertNotEmpty($write->revisions);
        $blocks = $write->revisions[0]['blocks'];
        $this->assertSame('rich_text', $blocks[0]['type'] ?? null);
        $this->assertArrayNotHasKey('format', $blocks[0]['data'] ?? []);
    }

    public function testToastUiAvailableKeepsMarkdownAndAddsFormat(): void
    {
        $db = $this->createDatabase();
        $this->seedEditor($db->pdo(), 1);

        $request = $this->makeRequest([
            'title' => 'Markdown',
            'slug' => 'markdown',
            'content' => '# Title',
            'content_format' => 'markdown',
            'editor_id' => 'toastui',
            'status' => 'draft',
            'blocks_json' => '[{"type":"rich_text","data":{"html":"<p>Block</p>"}}]',
        ]);
        $view = SecurityTestHelper::createView($db, $request, 'admin');
        $read = $this->readService();
        $write = new class implements PagesWriteServiceInterface {
            /** @var array<string, mixed> */
            public array $created = [];
            /** @var array<int, array{page_id: int, blocks: array, created_by: ?int}> */
            public array $revisions = [];

            public function create(array $data): array
            {
                $this->created = $data;
                return ['id' => 1];
            }

            public function update(int $id, array $data): void
            {
            }

            public function updateStatus(int $id, string $status): void
            {
            }

            public function delete(int $id): void
            {
            }

            public function createRevision(int $pageId, array $blocks, ?int $createdBy): int
            {
                $this->revisions[] = [
                    'page_id' => $pageId,
                    'blocks' => $blocks,
                    'created_by' => $createdBy,
                ];
                return 1;
            }

            public function deleteRevisionsByPageId(int $pageId): void
            {
            }
        };
        $rbac = new RbacService($db);
        $controller = new AdminPagesController($view, $read, $write, null, $rbac);

        $response = $controller->save($request);

        $this->assertSame(303, $response->getStatus());
        $this->assertSame('markdown', $write->created['content_format'] ?? null);
        $this->assertNotEmpty($write->revisions);
        $blocks = $write->revisions[0]['blocks'];
        $this->assertSame('rich_text', $blocks[0]['type'] ?? null);
        $this->assertSame('markdown', $blocks[0]['data']['format'] ?? null);
    }

    private function createDatabase(): DatabaseManager
    {
        $pdo = SecurityTestHelper::createSqlitePdo();
        SecurityTestHelper::seedRbacTables($pdo);
        SecurityTestHelper::seedPagesTable($pdo);
        SecurityTestHelper::seedSettingsTable($pdo);
        return SecurityTestHelper::dbManagerFromPdo($pdo);
    }

    private function seedEditor(PDO $pdo, int $userId): void
    {
        SecurityTestHelper::insertUser($pdo, $userId, 'editor', 'hash');
        SecurityTestHelper::insertRole($pdo, 2, 'editor');
        SecurityTestHelper::insertPermission($pdo, 1, 'pages.edit');
        SecurityTestHelper::assignRole($pdo, $userId, 2);
        SecurityTestHelper::grantPermission($pdo, 2, 1);
    }

    private function makeRequest(array $post): Request
    {
        $request = new Request('POST', '/admin/pages/save', [], $post, [], '');
        $session = new InMemorySession();
        $session->start();
        $session->set('user_id', 1);
        $request->setSession($session);
        return $request;
    }

    private function readService(): PagesReadServiceInterface
    {
        return new class implements PagesReadServiceInterface {
            /** @return array<int, array<string, mixed>> */
            public function list(array $filters = []): array
            {
                return [];
            }

            /** @return PageSummary[] */
            public function listPublishedSummaries(): array
            {
                return [];
            }

            public function getPublishedView(string $slug, string $locale, array $fields = [], array $include = []): ?PageView
            {
                return null;
            }

            public function count(array $filters = []): int
            {
                return 0;
            }

            /** @return array<string, mixed>|null */
            public function find(int $id): ?array
            {
                return null;
            }

            /** @return array<int, array{type: string, data: array<string, mixed>}> */
            public function findLatestBlocks(int $pageId): array
            {
                return [];
            }

            /** @return array<string, mixed>|null */
            public function findLatestRevision(int $pageId): ?array
            {
                return null;
            }

            public function findLatestRevisionId(int $pageId): int
            {
                return 0;
            }

            /** @return array<int, int> */
            public function findLatestRevisionIds(array $pageIds): array
            {
                return [];
            }
        };
    }
}
