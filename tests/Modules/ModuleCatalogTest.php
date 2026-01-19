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
            $this->assertArrayHasKey('module_id', $module);
            $this->assertArrayHasKey('type', $module);
            $this->assertArrayHasKey('enabled', $module);
            $this->assertArrayHasKey('admin_url', $module);
            $this->assertArrayHasKey('details_anchor', $module);
            $this->assertArrayHasKey('details_url', $module);
            $this->assertArrayHasKey('notes', $module);
            $this->assertArrayHasKey('virtual', $module);
            $this->assertArrayHasKey('icon', $module);
            $this->assertArrayHasKey('actions', $module);
            $this->assertArrayHasKey('actions_nav', $module);
            $this->assertNotSame('', (string) ($module['details_url'] ?? ''));
            $this->assertNotSame('', (string) ($module['details_anchor'] ?? ''));
        }
    }

    public function testVirtualAiModuleIsPresent(): void
    {
        $catalog = new ModuleCatalog(SecurityTestHelper::rootPath());

        $modules = $catalog->listAll();
        $ai = null;
        foreach ($modules as $module) {
            if (($module['name'] ?? null) === 'AI') {
                $ai = $module;
                break;
            }
        }

        $this->assertIsArray($ai);
        $this->assertSame('AI', $ai['name'] ?? null);
        $this->assertSame('internal', $ai['type'] ?? null);
        $this->assertTrue((bool) ($ai['enabled'] ?? false));
        $this->assertTrue((bool) ($ai['virtual'] ?? false));
        $this->assertSame('/admin/ai', $ai['admin_url'] ?? null);
        $this->assertSame('#module-ai', $ai['details_anchor'] ?? null);
        $this->assertSame('/admin/modules/details?module=ai', $ai['details_url'] ?? null);
        $this->assertSame('robot', $ai['icon'] ?? null);
        $this->assertIsArray($ai['actions'] ?? null);
        $this->assertNotEmpty($ai['actions']);
        $this->assertSame('Open', $ai['actions'][0]['label'] ?? null);
        $this->assertSame('/admin/ai', $ai['actions'][0]['url'] ?? null);
    }

    public function testUiModulesHaveAdminUrls(): void
    {
        $catalog = new ModuleCatalog(SecurityTestHelper::rootPath());

        $modules = $catalog->listAll();
        $this->assertSame('/admin', $this->findAdminUrl($modules, 'Admin'));
        $this->assertSame('/admin/pages', $this->findAdminUrl($modules, 'Pages'));
        $this->assertSame('/admin/media', $this->findAdminUrl($modules, 'Media'));
        $this->assertSame('/admin/menus', $this->findAdminUrl($modules, 'Menu'));
        $this->assertSame('/admin/users', $this->findAdminUrl($modules, 'Users'));
    }

    private function findAdminUrl(array $modules, string $name): ?string
    {
        foreach ($modules as $module) {
            if (($module['name'] ?? null) === $name) {
                return is_string($module['admin_url'] ?? null) ? $module['admin_url'] : null;
            }
        }

        return null;
    }
}
