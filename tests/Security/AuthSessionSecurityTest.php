<?php
declare(strict_types=1);

require_once __DIR__ . '/Support/SecurityTestHelper.php';

use Laas\Auth\AuthService;
use Laas\Database\Repositories\UsersRepository;
use Laas\Http\Request;
use Laas\Security\RateLimiter;
use Laas\Modules\Users\Controller\AuthController;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\Security\Support\SecurityTestHelper;

#[Group('security')]
final class AuthSessionSecurityTest extends TestCase
{
    private string $rootPath;

    protected function setUp(): void
    {
        $this->rootPath = SecurityTestHelper::rootPath();
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_unset();
            session_destroy();
        }
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        $this->clearRateLimit();
    }

    public function testLoginFailureDoesNotDiscloseUser(): void
    {
        $pdo = SecurityTestHelper::createSqlitePdo();
        SecurityTestHelper::seedRbacTables($pdo);
        SecurityTestHelper::insertUser($pdo, 1, 'admin', password_hash('secret', PASSWORD_DEFAULT));
        $db = SecurityTestHelper::dbManagerFromPdo($pdo);

        SecurityTestHelper::startSession($this->rootPath);
        $auth = new AuthService(new UsersRepository($pdo));

        $requestMissing = new Request('POST', '/login', [], [
            'username' => 'missing',
            'password' => 'wrong',
        ], ['hx-request' => 'true'], '');
        $viewMissing = SecurityTestHelper::createView($db, $requestMissing, 'default');
        $controllerMissing = new AuthController($viewMissing, $auth);
        $responseMissing = $controllerMissing->doLogin($requestMissing);

        $requestWrong = new Request('POST', '/login', [], [
            'username' => 'admin',
            'password' => 'wrong',
        ], ['hx-request' => 'true'], '');
        $viewWrong = SecurityTestHelper::createView($db, $requestWrong, 'default');
        $controllerWrong = new AuthController($viewWrong, $auth);
        $responseWrong = $controllerWrong->doLogin($requestWrong);

        $this->assertSame($responseMissing->getStatus(), $responseWrong->getStatus());
        $this->assertSame($responseMissing->getBody(), $responseWrong->getBody());
    }

    public function testLoginRateLimitApplies(): void
    {
        $limiter = new RateLimiter($this->rootPath);
        $first = $limiter->hit('login', '127.0.0.1', 60, 2);
        $second = $limiter->hit('login', '127.0.0.1', 60, 2);
        $third = $limiter->hit('login', '127.0.0.1', 60, 2);

        $this->assertTrue($first['allowed']);
        $this->assertTrue($second['allowed']);
        $this->assertFalse($third['allowed']);
    }

    public function testSessionIdRotatesAfterLogin(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        if (method_exists($pdo, 'sqliteCreateFunction')) {
            $pdo->sqliteCreateFunction('NOW', static fn(): string => '2026-01-01 00:00:00');
        }
        SecurityTestHelper::seedRbacTables($pdo);
        SecurityTestHelper::insertUser($pdo, 1, 'admin', password_hash('secret', PASSWORD_DEFAULT));

        SecurityTestHelper::startSession($this->rootPath);
        $before = session_id();
        $auth = new AuthService(new UsersRepository($pdo));
        $this->assertTrue($auth->attempt('admin', 'secret', '127.0.0.1'));
        $after = session_id();

        $this->assertNotSame($before, $after);
        $this->assertSame(1, $_SESSION['user_id'] ?? 0);
    }

    public function testSessionCookieFlags(): void
    {
        SecurityTestHelper::startSession($this->rootPath, [
            'secure' => true,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);

        $params = session_get_cookie_params();
        $this->assertTrue((bool) ($params['httponly'] ?? false));
        $this->assertTrue((bool) ($params['secure'] ?? false));
        $this->assertSame('Lax', (string) ($params['samesite'] ?? ''));
    }

    private function clearRateLimit(): void
    {
        $dir = $this->rootPath . '/storage/cache/ratelimit';
        if (!is_dir($dir)) {
            return;
        }
        foreach (glob($dir . '/*.json') ?: [] as $file) {
            @unlink($file);
        }
    }
}
