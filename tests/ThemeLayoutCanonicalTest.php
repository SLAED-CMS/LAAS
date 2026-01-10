<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class ThemeLayoutCanonicalTest extends TestCase
{
    public function testDefaultAndAdminHaveCanonicalLayout(): void
    {
        $root = dirname(__DIR__);

        $this->assertFileExists($root . '/themes/default/layouts/base.html');
        $this->assertFileExists($root . '/themes/admin/layouts/base.html');
    }
}
