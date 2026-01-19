<?php
declare(strict_types=1);

use Laas\Modules\ModuleCatalog;
use PHPUnit\Framework\TestCase;
use Tests\Security\Support\SecurityTestHelper;

final class ModuleCatalogTest extends TestCase
{
    public function testListAllReturnsNormalizedRows(): void
    {
        $catalog = new ModuleCatalog(SecurityTestHelper::rootPath());

        $modules = $catalog->listAll();

        $this->assertIsArray($modules);
        $this->assertNotEmpty($modules);

        foreach ($modules as $module) {
            $this->assertArrayHasKey('name', $module);
            $this->assertArrayHasKey('key', $module);
            $this->assertArrayHasKey('type', $module);
            $this->assertArrayHasKey('enabled', $module);
            $this->assertArrayHasKey('admin_url', $module);
            $this->assertArrayHasKey('notes', $module);
        }
    }
}
