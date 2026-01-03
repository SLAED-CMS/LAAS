<?php
declare(strict_types=1);

namespace {
    use Laas\Auth\NullAuthService;
    use Laas\Database\DatabaseManager;
    use Laas\DevTools\DevToolsContext;
    use Laas\I18n\Translator;
    use Laas\Http\Request;
    use Laas\Settings\SettingsProvider;
    use Laas\View\Template\TemplateCompiler;
    use Laas\View\Template\TemplateEngine;
    use Laas\View\Theme\ThemeManager;
    use Laas\View\View;
    use PHPUnit\Framework\TestCase;

    final class DevToolsMediaPanelTest extends TestCase
    {
        private string $rootPath;

        protected function setUp(): void
        {
            $this->rootPath = dirname(__DIR__);
        }

        public function testMediaPanelVisibleWithFlags(): void
        {
            $db = $this->createDatabase();
            $view = $this->createView($db, new Request('GET', '/admin', [], [], [], ''));

            $context = new DevToolsContext([
                'enabled' => true,
                'debug' => true,
                'env' => 'dev',
                'collect_db' => false,
                'collect_request' => false,
                'collect_logs' => false,
            ]);
            $context->setMedia([
                'id' => 10,
                'mime' => 'image/png',
                'size' => 123,
                'mode' => 'inline',
                'disk' => 'uploads/.../x.png',
                'storage' => 'local',
                'read_time_ms' => 2.5,
            ]);
            $context->finalize();

            $html = $view->renderPartial('partials/devtools_toolbar.html', [
                'devtools' => $context->toArray(),
            ], [
                'theme' => 'admin',
            ]);

            $this->assertStringContainsString('Media ID', $html);
        }

        public function testMediaPanelHiddenWithoutFlags(): void
        {
            $db = $this->createDatabase();
            $view = $this->createView($db, new Request('GET', '/admin', [], [], [], ''));

            $context = new DevToolsContext([
                'enabled' => false,
                'debug' => false,
                'env' => 'prod',
                'collect_db' => false,
                'collect_request' => false,
                'collect_logs' => false,
            ]);
            $context->setMedia([
                'id' => 10,
                'mime' => 'image/png',
                'size' => 123,
                'mode' => 'inline',
                'disk' => 'uploads/.../x.png',
                'storage' => 'local',
                'read_time_ms' => 2.5,
            ]);
            $context->finalize();

            $html = $view->renderPartial('partials/devtools_toolbar.html', [
                'devtools' => $context->toArray(),
            ], [
                'theme' => 'admin',
            ]);

            $this->assertStringNotContainsString('Media ID', $html);
        }

        private function createDatabase(): DatabaseManager
        {
            $pdo = new \PDO('sqlite::memory:');
            $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
            $pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);

            $db = new DatabaseManager(['driver' => 'mysql']);
            $ref = new \ReflectionProperty($db, 'pdo');
            $ref->setAccessible(true);
            $ref->setValue($db, $pdo);

            return $db;
        }

        private function createView(DatabaseManager $db, Request $request): View
        {
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
            $view = new View(
                $themeManager,
                $engine,
                $translator,
                'en',
                ['name' => 'LAAS', 'debug' => true],
                new NullAuthService(),
                $settings,
                $this->rootPath . '/storage/cache/templates',
                $db
            );
            $view->setRequest($request);

            return $view;
        }
    }
}
