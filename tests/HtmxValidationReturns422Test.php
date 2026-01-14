<?php
declare(strict_types=1);

require_once __DIR__ . '/Security/Support/SecurityTestHelper.php';

use Laas\Auth\NullAuthService;
use Laas\Auth\TotpService;
use Laas\Database\DatabaseManager;
use Laas\Database\Repositories\UsersRepository;
use Laas\Http\Request;
use Laas\Modules\Users\Controller\AuthController;
use PHPUnit\Framework\TestCase;
use Tests\Security\Support\SecurityTestHelper;

final class HtmxValidationReturns422Test extends TestCase
{
    public function testHtmxValidationReturns422(): void
    {
        $request = new Request('POST', '/login', [], [
            'username' => '',
            'password' => '',
        ], ['hx-request' => 'true'], '');

        $db = new DatabaseManager(['driver' => 'sqlite', 'database' => ':memory:']);
        $view = SecurityTestHelper::createView($db, $request, 'default');
        $controller = new AuthController($view, new NullAuthService(), new UsersRepository($db->pdo()), new TotpService());

        $response = $controller->doLogin($request);

        $this->assertSame(422, $response->getStatus());
    }
}
