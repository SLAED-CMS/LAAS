<?php
declare(strict_types=1);

use Laas\Auth\NullAuthService;
use Laas\Database\DatabaseManager;
use Laas\Http\Request;
use Laas\I18n\Translator;
use Laas\Modules\Changelog\Controller\ChangelogController;
use Laas\Settings\SettingsProvider;
use Laas\View\Template\TemplateCompiler;
use Laas\View\Template\TemplateEngine;
use Laas\View\Theme\ThemeManager;
use Laas\View\AssetManager;
use Laas\View\View;
use PHPUnit\Framework\TestCase;

final class FrontendControllerTest extends TestCase
{
    private string $rootPath;

    protected function setUp(): void
    {
        $this->rootPath = dirname(__DIR__, 2);
    }

    public function testRendersList(): void
    {
        $db = $this->createDatabase();
        $request = new Request('GET', '/changelog', [], [], [], '');
        $view = $this->createView($db, $request);
        $controller = new ChangelogController($view, $db);

        $response = $controller->index($request);

        $this->assertSame(200, $response->getStatus());
        $this->assertStringContainsString('Changelog', $response->getBody());
    }

    public function testNoSensitiveInfoOnFailure(): void
    {
        $db = $this->createDatabase();
        $pdo = $db->pdo();
        $pdo->exec("INSERT INTO settings (`key`, `value`, `type`, `updated_at`) VALUES ('changelog.enabled', '1', 'bool', '2026-01-01 00:00:00')");
        $pdo->exec("INSERT INTO settings (`key`, `value`, `type`, `updated_at`) VALUES ('changelog.source_type', 'github', 'string', '2026-01-01 00:00:00')");
        $pdo->exec("INSERT INTO settings (`key`, `value`, `type`, `updated_at`) VALUES ('changelog.github_owner', '', 'string', '2026-01-01 00:00:00')");
        $pdo->exec("INSERT INTO settings (`key`, `value`, `type`, `updated_at`) VALUES ('changelog.github_repo', '', 'string', '2026-01-01 00:00:00')");

        putenv('GITHUB_TOKEN=supersecret');

        $request = new Request('GET', '/changelog', [], [], [], '');
        $view = $this->createView($db, $request);
        $controller = new ChangelogController($view, $db);

        $response = $controller->index($request);
        $body = $response->getBody();

        $this->assertStringNotContainsString('supersecret', $body);
        $this->assertStringNotContainsString('GITHUB_TOKEN', $body);
    }

    private function createDatabase(): DatabaseManager
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec('CREATE TABLE settings (`key` TEXT PRIMARY KEY, `value` TEXT, `type` TEXT, `updated_at` TEXT)');

        $db = new DatabaseManager(['driver' => 'mysql']);
        $ref = new ReflectionProperty($db, 'pdo');
        $ref->setAccessible(true);
        $ref->setValue($db, $pdo);

        return $db;
    }

    private function createView(DatabaseManager $db, Request $request): View
    {
        $settings = new SettingsProvider($db, [
            'site_name' => 'LAAS',
            'default_locale' => 'en',
            'theme' => 'default',
        ], ['site_name', 'default_locale', 'theme']);

        $themeManager = new ThemeManager($this->rootPath . '/themes', 'default', $settings);
        $engine = new TemplateEngine(
            $themeManager,
            new TemplateCompiler(),
            $this->rootPath . '/storage/cache/templates',
            true
        );
        $translator = new Translator($this->rootPath, 'default', 'en');
        $view = new View(
            $themeManager,
            $engine,
            $translator,
            'en',
            ['name' => 'LAAS', 'debug' => true],
            new AssetManager([]),
            new NullAuthService(),
            $settings,
            $this->rootPath . '/storage/cache/templates',
            $db
        );
        $view->setRequest($request);

        return $view;
    }
}
