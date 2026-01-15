<?php
declare(strict_types=1);

use Laas\Database\DatabaseManager;
use Laas\Http\Request;
use Laas\Settings\SettingsProvider;
use Laas\Support\RequestScope;
use Laas\View\SanitizedHtml;
use Laas\View\Template\TemplateCompiler;
use Laas\View\Template\TemplateEngine;
use Laas\View\Theme\ThemeManager;
use PHPUnit\Framework\TestCase;
use Tests\Support\InMemorySession;

final class TemplateRawGuardTest extends TestCase
{
    protected function tearDown(): void
    {
        RequestScope::setRequest(null);
        RequestScope::reset();
    }

    public function testRawAllowsSanitizedHtml(): void
    {
        $engine = $this->createEngine('strict', false);
        $events = [];

        $this->withRequest(function () use ($engine, &$events): void {
            RequestScope::set('template.raw_audit', function (string $action, array $context) use (&$events): void {
                $events[] = [$action, $context];
            });

            $html = $engine->raw(
                SanitizedHtml::fromSanitized('<strong>ok</strong>'),
                'page.content',
                [],
                ['template' => 'pages/page.html']
            );

            $this->assertSame('<strong>ok</strong>', $html);
        });

        $this->assertSame('template.raw_used', $events[0][0] ?? null);
        $this->assertSame('pages/page.html', $events[0][1]['template'] ?? null);
    }

    public function testRawEscapesStringInEscapeMode(): void
    {
        $engine = $this->createEngine('escape', false);
        $events = [];

        $this->withRequest(function () use ($engine, &$events): void {
            RequestScope::set('template.raw_audit', function (string $action, array $context) use (&$events): void {
                $events[] = [$action, $context];
            });

            $html = $engine->raw('<script>alert(1)</script>', 'page.content', [], ['template' => 'pages/page.html']);
            $this->assertSame('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        });

        $this->assertSame('template.raw_blocked', $events[0][0] ?? null);
    }

    public function testRawThrowsInStrictDebugMode(): void
    {
        $engine = $this->createEngine('strict', true);

        $this->withRequest(function () use ($engine): void {
            $this->expectException(RuntimeException::class);
            $engine->raw('<em>nope</em>', 'page.content', [], ['template' => 'pages/page.html']);
        });
    }

    private function withRequest(callable $callback): void
    {
        $session = new InMemorySession();
        $session->start();
        $request = new Request('GET', '/test', [], [], [], '', $session);
        RequestScope::setRequest($request);
        $callback();
    }

    private function createEngine(string $rawMode, bool $debug): TemplateEngine
    {
        $root = dirname(__DIR__);
        $db = new DatabaseManager(['driver' => 'sqlite', 'database' => ':memory:']);
        $settings = new SettingsProvider($db, [
            'site_name' => 'LAAS',
            'default_locale' => 'en',
            'theme' => 'default',
        ], ['site_name', 'default_locale', 'theme']);

        $themeManager = new ThemeManager($root . '/themes', 'default', $settings);
        return new TemplateEngine(
            $themeManager,
            new TemplateCompiler(),
            $root . '/storage/cache/templates',
            $debug,
            $rawMode
        );
    }
}
