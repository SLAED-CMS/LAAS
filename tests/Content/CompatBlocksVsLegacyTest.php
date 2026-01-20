<?php
declare(strict_types=1);

use Laas\Auth\NullAuthService;
use Laas\Database\DatabaseManager;
use Laas\Domain\Pages\PagesService;
use Laas\Http\Request;
use Laas\I18n\Translator;
use Laas\Modules\Pages\Controller\PagesController;
use Laas\Settings\SettingsProvider;
use Laas\View\AssetManager;
use Laas\View\Template\TemplateCompiler;
use Laas\View\Template\TemplateEngine;
use Laas\View\Theme\ThemeManager;
use Laas\View\View;
use PHPUnit\Framework\TestCase;

final class CompatBlocksVsLegacyTest extends TestCase
{
    private string $rootPath;
    private ?string $previousDebug = null;

    protected function setUp(): void
    {
        $this->rootPath = dirname(__DIR__, 2);
        $this->previousDebug = $_ENV['APP_DEBUG'] ?? null;
        $_ENV['APP_DEBUG'] = 'true';
    }

    protected function tearDown(): void
    {
        if ($this->previousDebug === null) {
            unset($_ENV['APP_DEBUG']);
        } else {
            $_ENV['APP_DEBUG'] = $this->previousDebug;
        }
    }

    public function testBlocksWinAndLegacyFallbacksWhenEmpty(): void
    {
        $db = $this->createDatabase();
        $pdo = $db->pdo();

        $pdo->exec("INSERT INTO pages (id, title, slug, content, status, created_at, updated_at) VALUES (1, 'Blocks', 'blocks', '<p>Legacy</p>', 'published', '2026-01-01 00:00:00', '2026-01-01 00:00:00')");
        $pdo->exec("INSERT INTO pages_revisions (page_id, blocks_json, created_at, created_by) VALUES (1, '[{\"type\":\"rich_text\",\"data\":{\"html\":\"<p>Block</p>\"}}]', '2026-01-01 00:00:00', 1)");
        $pdo->exec("INSERT INTO pages (id, title, slug, content, status, created_at, updated_at) VALUES (2, 'Legacy', 'legacy', '<p>Legacy only</p>', 'published', '2026-01-01 00:00:00', '2026-01-01 00:00:00')");
        $pdo->exec("INSERT INTO pages_revisions (page_id, blocks_json, created_at, created_by) VALUES (2, '[]', '2026-01-01 00:00:00', 1)");

        $view = $this->createView($db);
        $controller = new PagesController($view, $db, new PagesService($db));

        $blocksRequest = new Request('GET', '/blocks', [], [], [], '');
        $view->setRequest($blocksRequest);
        $blocksResponse = $controller->show($blocksRequest, ['slug' => 'blocks']);
        $blocksBody = $blocksResponse->getBody();

        $this->assertSame(200, $blocksResponse->getStatus());
        $this->assertStringContainsString('block-richtext', $blocksBody);
        $this->assertStringNotContainsString('<p>Legacy</p>', $blocksBody);

        $legacyRequest = new Request('GET', '/legacy', [], [], [], '');
        $view->setRequest($legacyRequest);
        $legacyResponse = $controller->show($legacyRequest, ['slug' => 'legacy']);
        $legacyBody = $legacyResponse->getBody();

        $this->assertSame(200, $legacyResponse->getStatus());
        $this->assertStringContainsString('Legacy only', $legacyBody);
        $this->assertStringNotContainsString('block-richtext', $legacyBody);
    }

    private function createDatabase(): DatabaseManager
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec('CREATE TABLE pages (id INTEGER PRIMARY KEY AUTOINCREMENT, title TEXT, slug TEXT, content TEXT, status TEXT, created_at TEXT, updated_at TEXT)');
        $pdo->exec('CREATE TABLE pages_revisions (id INTEGER PRIMARY KEY AUTOINCREMENT, page_id INTEGER, blocks_json TEXT, created_at TEXT, created_by INTEGER)');

        $db = new DatabaseManager(['driver' => 'mysql']);
        $ref = new ReflectionProperty($db, 'pdo');
        $ref->setAccessible(true);
        $ref->setValue($db, $pdo);

        return $db;
    }

    private function createView(DatabaseManager $db): View
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
        return new View(
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
    }
}
