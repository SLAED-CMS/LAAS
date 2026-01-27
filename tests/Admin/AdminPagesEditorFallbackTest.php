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

final class AdminPagesEditorFallbackTest extends TestCase
{
    private ?string $previousAssetBase = null;

    protected function setUp(): void
    {
        $this->previousAssetBase = $_ENV['ASSET_BASE'] ?? null;
        $_ENV['ASSET_BASE'] = '/_assets_missing';
    }

    protected function tearDown(): void
    {
        if ($this->previousAssetBase === null) {
            unset($_ENV['ASSET_BASE']);
        } else {
            $_ENV['ASSET_BASE'] = $this->previousAssetBase;
        }
    }

    public function testMarkdownSelectionFallsBackToTextareaWhenToastUiMissing(): void
    {
        $db = $this->createDatabase();
        $this->seedEditor($db->pdo(), 1);

        $request = $this->makeRequest('GET', '/admin/pages/1/edit', 1);
        $view = SecurityTestHelper::createView($db, $request, 'admin');
        $pagesRead = new class implements PagesReadServiceInterface {
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
                return [
                    'id' => $id,
                    'title' => 'Sample',
                    'slug' => 'sample',
                    'content' => '',
                    'content_format' => 'markdown',
                    'status' => 'draft',
                    'blocks_json' => '[]',
                ];
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
        $pagesWrite = new class implements PagesWriteServiceInterface {
            /** @param array<string, mixed> $data */
            public function create(array $data): array
            {
                return [];
            }

            /** @param array<string, mixed> $data */
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
                return 0;
            }

            public function deleteRevisionsByPageId(int $pageId): void
            {
            }
        };
        $rbac = new RbacService($db);
        $controller = new AdminPagesController($view, $pagesRead, $pagesWrite, null, $rbac);

        $response = $controller->editForm($request, ['id' => 1]);

        $this->assertSame(200, $response->getStatus());
        $this->assertStringContainsString('data-editor-selected-id="textarea"', $response->getBody());
        $this->assertStringContainsString('data-editor-selected-format="html"', $response->getBody());
        $this->assertStringContainsString('name="content_format" value="html"', $response->getBody());
        $this->assertStringContainsString('data-editor-unavailable-hint="1"', $response->getBody());
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

    private function makeRequest(string $method, string $path, int $userId): Request
    {
        $request = new Request($method, $path, [], [], [], '');
        $session = new InMemorySession();
        $session->start();
        $session->set('user_id', $userId);
        $request->setSession($session);
        return $request;
    }
}
