<?php
declare(strict_types=1);

use Laas\Support\RequestScope;
use Laas\View\Template\TemplateCompiler;
use Laas\View\Template\TemplateEngine;
use Laas\View\Theme\ThemeManager;
use PHPUnit\Framework\TestCase;

final class TemplateRawStrictModeTest extends TestCase
{
    protected function tearDown(): void
    {
        RequestScope::setRequest(null);
        RequestScope::reset();
    }

    public function testStrictModeBlocksUnsanitizedRaw(): void
    {
        $root = dirname(__DIR__);
        $themeManager = new ThemeManager($root . '/themes', 'default', null);
        $engine = new TemplateEngine(
            $themeManager,
            new TemplateCompiler(),
            $root . '/storage/cache/templates',
            true,
            'strict'
        );

        $this->expectException(\RuntimeException::class);
        $engine->raw('<em>unsafe</em>', 'page.content', [], ['template' => 'pages/page.html']);
    }
}
