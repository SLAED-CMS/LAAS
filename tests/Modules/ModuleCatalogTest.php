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
            $this->assertArrayHasKey('group', $module);
            $this->assertArrayHasKey('pinned', $module);
            $this->assertArrayHasKey('nav_priority', $module);
            $this->assertArrayHasKey('nav_label', $module);
            $this->assertArrayHasKey('nav_badge', $module);
            $this->assertArrayHasKey('nav_badge_tone', $module);
            $this->assertArrayHasKey('nav_search', $module);
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
        $this->assertSame('demo', $ai['group'] ?? null);
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

    public function testNavSortingHonorsGroupAndPinned(): void
    {
        $root = SecurityTestHelper::rootPath();
        $navConfig = [
            'pinned' => ['Media'],
            'modules' => [
                'Users' => ['group' => 'core', 'nav_priority' => 20],
                'Admin' => ['group' => 'system', 'nav_priority' => 10],
                'Pages' => ['group' => 'content', 'nav_priority' => 20],
                'Media' => ['group' => 'content', 'nav_priority' => 10, 'pinned' => true],
            ],
        ];

        $catalog = new ModuleCatalog($root, null, null, $navConfig);
        $modules = $catalog->listNav();

        $this->assertLessThan($this->indexOfModule($modules, 'Admin'), $this->indexOfModule($modules, 'Users'));
        $this->assertLessThan($this->indexOfModule($modules, 'Pages'), $this->indexOfModule($modules, 'Media'));
    }

    public function testNavActionsRespectAllowlist(): void
    {
        $root = SecurityTestHelper::rootPath();
        $navConfig = [
            'actions_allowlist' => ['/admin/pages', '/admin/modules'],
        ];

        $catalog = new ModuleCatalog($root, null, null, $navConfig);
        $modules = $catalog->listAll();
        $pages = $this->findModule($modules, 'Pages');

        $this->assertIsArray($pages);
        $labels = array_map(static fn(array $action): string => (string) ($action['label'] ?? ''), $pages['actions_nav'] ?? []);
        $this->assertContains('Open', $labels);
        $this->assertNotContains('New', $labels);
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

    private function findModule(array $modules, string $name): ?array
    {
        foreach ($modules as $module) {
            if (($module['name'] ?? null) === $name) {
                return $module;
            }
        }

        return null;
    }

    private function indexOfModule(array $modules, string $name): int
    {
        foreach ($modules as $index => $module) {
            if (($module['name'] ?? null) === $name) {
                return (int) $index;
            }
        }

        return -1;
    }
}
