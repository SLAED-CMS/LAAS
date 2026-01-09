<?php
declare(strict_types=1);

use Laas\View\Theme\ThemeManager;
use PHPUnit\Framework\TestCase;

final class ThemeManagerTest extends TestCase
{
    public function testReadsThemeJson(): void
    {
        $root = $this->makeTempDir('theme-json');
        $themePath = $root . '/site';
        mkdir($themePath . '/layouts', 0775, true);
        file_put_contents($themePath . '/layouts/base.html', '<!doctype html>');
        file_put_contents($themePath . '/theme.json', json_encode([
            'name' => 'site',
            'version' => '1.0.0',
            'author' => 'LAAS CMS',
            'layouts' => [
                'base' => 'layouts/base.html',
            ],
            'assets_profile' => 'default',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $manager = new ThemeManager($root, 'site', null);
        $config = $manager->getThemeConfig();

        $this->assertSame('site', $config['name'] ?? null);
        $this->assertSame('layouts/base.html', $config['layouts']['base'] ?? null);
    }

    public function testFallbackWhenThemeJsonMissing(): void
    {
        $root = $this->makeTempDir('theme-fallback');
        $themePath = $root . '/legacy';
        mkdir($themePath, 0775, true);
        file_put_contents($themePath . '/layout.html', '<!doctype html>');

        $manager = new ThemeManager($root, 'legacy', null);
        $this->assertSame('layout.html', $manager->getLayoutPath('base'));
    }

    public function testValidatesBaseLayoutPath(): void
    {
        $root = $this->makeTempDir('theme-invalid');
        $themePath = $root . '/broken';
        mkdir($themePath, 0775, true);
        file_put_contents($themePath . '/theme.json', json_encode([
            'name' => 'broken',
            'version' => '1.0.0',
            'author' => 'LAAS CMS',
            'layouts' => [
                'base' => 'layouts/missing.html',
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $manager = new ThemeManager($root, 'broken', null);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Base layout not found');

        $manager->getThemeConfig();
    }

    private function makeTempDir(string $suffix): string
    {
        $root = rtrim(sys_get_temp_dir(), '/\\') . '/laas-theme-' . $suffix . '-' . uniqid('', true);
        mkdir($root, 0775, true);
        return $root;
    }
}
