<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class ToastContainerRenderedInAdminLayoutTest extends TestCase
{
    public function testAdminLayoutIncludesToastContainerAndScripts(): void
    {
        $root = dirname(__DIR__, 2);
        $layout = file_get_contents($root . '/themes/admin/layout.html');
        $partial = file_get_contents($root . '/themes/admin/partials/toasts.html');

        $this->assertStringContainsString('id="laas-toasts"', $partial);
        $this->assertStringContainsString('id="laas-toast-template"', $partial);
        $this->assertStringContainsString('{% include "partials/toasts.html" %}', $layout);
        $this->assertStringContainsString('{% assets.admin_js %}', $layout);
    }
}
