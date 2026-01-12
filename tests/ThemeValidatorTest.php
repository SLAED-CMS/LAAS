<?php
declare(strict_types=1);

use Laas\Theme\ThemeValidator;
use PHPUnit\Framework\TestCase;

final class ThemeValidatorTest extends TestCase
{
    public function testMissingBaseLayoutIsViolation(): void
    {
        $root = $this->makeTempDir('theme-validator-missing');
        $themePath = $root . '/site';
        mkdir($themePath . '/partials', 0775, true);
        file_put_contents($themePath . '/partials/header.html', '<div></div>');

        $validator = new ThemeValidator($root);
        $result = $validator->validateTheme('site');

        $codes = array_map(static fn(array $row): string => $row['code'], $result->getViolations());
        $this->assertContains('layout_missing', $codes);
    }

    public function testInlineStyleIsViolation(): void
    {
        $root = $this->makeTempDir('theme-validator-style');
        $themePath = $root . '/site';
        mkdir($themePath . '/layouts', 0775, true);
        mkdir($themePath . '/partials', 0775, true);
        file_put_contents($themePath . '/layouts/base.html', '<html></html>');
        file_put_contents($themePath . '/partials/header.html', '<div></div>');
        file_put_contents($themePath . '/pages.html', '<style>.x{}</style>');

        $validator = new ThemeValidator($root);
        $result = $validator->validateTheme('site');

        $codes = array_map(static fn(array $row): string => $row['code'], $result->getViolations());
        $this->assertContains('inline_style', $codes);
    }

    private function makeTempDir(string $suffix): string
    {
        $root = rtrim(sys_get_temp_dir(), '/\\') . '/laas-theme-' . $suffix . '-' . uniqid('', true);
        mkdir($root, 0775, true);
        return $root;
    }
}
