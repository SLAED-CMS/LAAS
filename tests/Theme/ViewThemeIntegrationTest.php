<?php
declare(strict_types=1);

use Laas\Theme\TemplateResolver;
use Laas\Theme\ThemeRegistry;
use PHPUnit\Framework\TestCase;

final class ViewThemeIntegrationTest extends TestCase
{
    public function testDefaultThemeTemplateResolves(): void
    {
        $root = dirname(__DIR__, 2);
        $registry = new ThemeRegistry($root . '/themes', 'default');
        $resolver = new TemplateResolver();

        $theme = $registry->default();
        $path = $resolver->resolve('layout.html', $theme);

        $this->assertFileExists($path);
    }
}
