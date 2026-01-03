<?php
declare(strict_types=1);

use Laas\Support\Rbac\PermissionGrouper;
use PHPUnit\Framework\TestCase;

final class PermissionGrouperTest extends TestCase
{
    public function testGroupsByPrefix(): void
    {
        $grouper = new PermissionGrouper();
        $groups = $grouper->group([
            ['name' => 'admin.access', 'title' => null],
            ['name' => 'pages.edit', 'title' => null],
            ['name' => 'custom.permission', 'title' => null],
        ]);

        $this->assertArrayHasKey('admin', $groups);
        $this->assertArrayHasKey('pages', $groups);
        $this->assertArrayHasKey('other', $groups);
    }
}
