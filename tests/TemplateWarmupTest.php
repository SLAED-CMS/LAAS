<?php
declare(strict_types=1);

use Laas\View\Template\TemplateCompiler;
use Laas\View\Template\TemplateEngine;
use Laas\View\Template\TemplateWarmupService;
use Laas\View\Theme\ThemeManager;
use PHPUnit\Framework\TestCase;

final class TemplateWarmupTest extends TestCase
{
    public function testWarmupCompilesTemplates(): void
    {
        $root = sys_get_temp_dir() . '/laas_tpl_' . bin2hex(random_bytes(4));
        @mkdir($root . '/themes/site/partials', 0775, true);
        @mkdir($root . '/themes/site/pages', 0775, true);
        @mkdir($root . '/storage/cache/templates', 0775, true);

        file_put_contents($root . '/themes/site/layout.html', "<html>{% block content %}{% endblock %}</html>");
        file_put_contents($root . '/themes/site/pages/home.html', "{% extends 'layout.html' %}{% block content %}Hi{% endblock %}");
        file_put_contents($root . '/themes/site/partials/box.html', "<div>Box</div>");
        file_put_contents($root . '/themes/site/pages/with_include.html', "{% include 'partials/box.html' %}");

        $themeManager = new ThemeManager($root . '/themes', 'site', null);
        $engine = new TemplateEngine(
            $themeManager,
            new TemplateCompiler(),
            $root . '/storage/cache/templates',
            false
        );
        $warmup = new TemplateWarmupService($engine);
        $result = $warmup->warmupTheme($themeManager);

        $this->assertSame([], $result['errors']);
        $this->assertGreaterThan(0, $result['compiled']);

        $cacheFiles = glob($root . '/storage/cache/templates/*.php') ?: [];
        $this->assertNotEmpty($cacheFiles);
        $this->assertSame(count($cacheFiles), $result['compiled']);
    }
}
