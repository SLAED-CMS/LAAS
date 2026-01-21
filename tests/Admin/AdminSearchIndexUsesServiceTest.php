<?php
declare(strict_types=1);

use Laas\Database\DatabaseManager;
use Laas\Domain\AdminSearch\AdminSearchService;
use Laas\Http\Request;
use Laas\Modules\Admin\Controller\AdminSearchController;
use Laas\View\View;
use PHPUnit\Framework\TestCase;
use Tests\Security\Support\SecurityTestHelper;
use Tests\Support\InMemorySession;

final class AdminSearchIndexUsesServiceTest extends TestCase
{
    public function testIndexUsesService(): void
    {
        $pdo = SecurityTestHelper::createSqlitePdo();
        $this->seedRbac($pdo);

        $request = $this->makeRequest('GET', '/admin/search', 'page');
        $db = SecurityTestHelper::dbManagerFromPdo($pdo);
        $view = $this->createView($db, $request);
        $service = new SpyAdminSearchService();
        $container = SecurityTestHelper::createContainer($db);
        $controller = new AdminSearchController($view, $service, $container);

        $response = $controller->index($request);

        $this->assertSame(200, $response->getStatus());
        $this->assertTrue($service->searchCalled);
    }

    private function seedRbac(\PDO $pdo): void
    {
        SecurityTestHelper::seedRbacTables($pdo);
        SecurityTestHelper::insertUser($pdo, 1, 'admin', 'hash');
    }

    private function makeRequest(string $method, string $path, string $query): Request
    {
        $request = new Request($method, $path, ['q' => $query], [], ['hx-request' => 'true'], '');
        $session = new InMemorySession();
        $session->start();
        $session->set('user_id', 1);
        $request->setSession($session);
        return $request;
    }

    private function createView(DatabaseManager $db, Request $request): View
    {
        return SecurityTestHelper::createView($db, $request, 'admin');
    }
}

final class SpyAdminSearchService extends AdminSearchService
{
    public bool $searchCalled = false;

    public function __construct()
    {
    }

    public function search(string $q, array $opts = []): array
    {
        $this->searchCalled = true;
        return [
            'q' => $q,
            'total' => 0,
            'groups' => [],
            'reason' => null,
        ];
    }
}
