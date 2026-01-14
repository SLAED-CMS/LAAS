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

        $this->assertStringContainsString('<div id="laas-toasts" class="toast-container position-fixed bottom-0 end-0 p-3"></div>', $partial);
        $this->assertStringContainsString('{% include "partials/toasts.html" %}', $layout);
        $this->assertStringContainsString('{% assets.admin_js %}', $layout);
    }
}
