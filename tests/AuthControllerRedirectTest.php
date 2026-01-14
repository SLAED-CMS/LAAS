<?php
declare(strict_types=1);

require_once __DIR__ . '/Security/Support/SecurityTestHelper.php';

use Laas\Auth\AuthInterface;
use Laas\Auth\TotpService;
use Laas\Database\Repositories\UsersRepository;
use Laas\Http\Request;
use Laas\Modules\Users\Controller\AuthController;
use Laas\Auth\NullAuthService;
use Laas\Database\DatabaseManager;
use Laas\I18n\Translator;
use Laas\Settings\SettingsProvider;
use Laas\View\Template\TemplateCompiler;
use Laas\View\Template\TemplateEngine;
use Laas\View\Theme\ThemeManager;
use Laas\View\AssetManager;
use Laas\View\View;
use PHPUnit\Framework\TestCase;
use Tests\Security\Support\SecurityTestHelper;

final class AuthControllerRedirectTest extends TestCase
{
    private string $rootPath;

    protected function setUp(): void
    {
        $this->rootPath = dirname(__DIR__);
    }

    public function testLoginRedirectsToAdminOnly(): void
    {
        $auth = new class implements AuthInterface {
            public function attempt(string $username, string $password, string $ip): bool { return true; }
            public function logout(): void {}
            public function user(): ?array { return ['id' => 1]; }
            public function check(): bool { return true; }
        };

        $request = new Request('POST', '/login', ['next' => 'http://evil.test'], [
            'username' => 'admin',
            'password' => 'secret',
        ], [], '');

        $view = $this->createView();
        $pdo = SecurityTestHelper::createSqlitePdo();
        SecurityTestHelper::seedRbacTables($pdo);
        SecurityTestHelper::insertUser($pdo, 1, 'admin', password_hash('secret', PASSWORD_DEFAULT));
        $usersRepo = new UsersRepository($pdo);
        $controller = new AuthController($view, $auth, $usersRepo, new TotpService());
        $response = $controller->doLogin($request);

        $this->assertSame(303, $response->getStatus());
        $this->assertSame('/admin', $response->getHeader('Location'));
    }

    public function testLogoutRedirectsToRootOnly(): void
    {
        $auth = new class implements AuthInterface {
            public function attempt(string $username, string $password, string $ip): bool { return true; }
            public function logout(): void {}
            public function user(): ?array { return ['id' => 1]; }
            public function check(): bool { return true; }
        };

        $request = new Request('POST', '/logout', ['next' => 'http://evil.test'], [], [], '');
        $view = $this->createView();
        $db = new DatabaseManager(['driver' => 'sqlite', 'database' => ':memory:']);
        $usersRepo = new UsersRepository($db->pdo());
        $controller = new AuthController($view, $auth, $usersRepo, new TotpService());
        $response = $controller->doLogout($request);

        $this->assertSame(303, $response->getStatus());
        $this->assertSame('/', $response->getHeader('Location'));
    }

    private function createView(): View
    {
        $db = new DatabaseManager(['driver' => 'sqlite', 'database' => ':memory:']);
        $settings = new SettingsProvider($db, [
            'site_name' => 'LAAS',
            'default_locale' => 'en',
            'theme' => 'admin',
        ], ['site_name', 'default_locale', 'theme']);

        $themeManager = new ThemeManager($this->rootPath . '/themes', 'admin', $settings);
        $engine = new TemplateEngine(
            $themeManager,
            new TemplateCompiler(),
            $this->rootPath . '/storage/cache/templates',
            false
        );
        $translator = new Translator($this->rootPath, 'admin', 'en');
        return new View(
            $themeManager,
            $engine,
            $translator,
            'en',
            ['name' => 'LAAS', 'debug' => false],
            new AssetManager([]),
            new NullAuthService(),
            $settings,
            $this->rootPath . '/storage/cache/templates',
            $db
        );
    }
}
