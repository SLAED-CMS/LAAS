<?php
declare(strict_types=1);

require_once __DIR__ . '/Security/Support/SecurityTestHelper.php';

use Laas\Auth\NullAuthService;
use Laas\Auth\TotpService;
use Laas\Database\DatabaseManager;
use Laas\Domain\Users\UsersService;
use Laas\Http\Request;
use Laas\Modules\Users\Controller\AuthController;
use PHPUnit\Framework\TestCase;
use Tests\Security\Support\SecurityTestHelper;

final class HtmlValidationReturns422Test extends TestCase
{
    public function testHtmlValidationReturns422(): void
    {
        $request = new Request('POST', '/login', [], [
            'username' => 'admin',
            'password' => 'wrong',
        ], [], '');

        $db = new DatabaseManager(['driver' => 'sqlite', 'database' => ':memory:']);
        $db->pdo()->exec('CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, username TEXT, status INTEGER, password_hash TEXT)');
        $view = SecurityTestHelper::createView($db, $request, 'default');
        $usersService = new UsersService($db);
        $controller = new AuthController($view, new NullAuthService(), $usersService, $usersService, new TotpService());

        $response = $controller->doLogin($request);

        $this->assertSame(422, $response->getStatus());
    }
}
