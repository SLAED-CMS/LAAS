<?php

declare(strict_types=1);

namespace Laas\Modules;

use Laas\Database\DatabaseManager;
use Laas\DevTools\ModulesDiscoveryStats;

final class AdminModulesNavSnapshot
{
    private string $cachePath;
    private string $rootPath;
    private ?DatabaseManager $db;
    /** @var array<string, mixed>|null */
    private ?array $configModules;
    /** @var array<string, mixed>|null */
    private ?array $navConfig;

    /**
     * @param array<string, mixed>|null $configModules
     * @param array<string, mixed>|null $navConfig
     */
    public function __construct(
        string $cachePath,
        string $rootPath,
        ?DatabaseManager $db = null,
        ?array $configModules = null,
        ?array $navConfig = null
    ) {
        $this->cachePath = $cachePath;
        $this->rootPath = $rootPath;
        $this->db = $db;
        $this->configModules = $configModules;
        $this->navConfig = $navConfig;
    }

    /** @return array{nav: array<int, array<string, mixed>>, sections: array<int, array<string, mixed>>}|null */
    public function load(): ?array
    {
        if (!is_file($this->cachePath)) {
            ModulesDiscoveryStats::recordMeta('admin_nav_cache', 'miss');
            return null;
        }

        $data = @include $this->cachePath;
        if (!is_array($data)) {
            ModulesDiscoveryStats::recordMeta('admin_nav_cache', 'miss');
            return null;
        }

        $nav = $data['nav'] ?? null;
        $sections = $data['sections'] ?? null;
        if (!is_array($nav) || !is_array($sections)) {
            ModulesDiscoveryStats::recordMeta('admin_nav_cache', 'miss');
            return null;
        }

        ModulesDiscoveryStats::recordMeta('admin_nav_cache', 'hit');
        return [
            'nav' => $nav,
            'sections' => $sections,
        ];
    }

    /** @return array{nav: array<int, array<string, mixed>>, sections: array<int, array<string, mixed>>} */
    public function rebuild(): array
    {
        $t0 = microtime(true);
        $catalog = new ModuleCatalog(
            $this->rootPath,
            $this->db,
            $this->configModules,
            $this->navConfig
        );

        $nav = $catalog->listNav();
        $sections = $catalog->listNavSections();

        ModulesDiscoveryStats::recordMeta('admin_nav_cache', 'rebuild');
        ModulesDiscoveryStats::record('admin_nav', (microtime(true) - $t0) * 1000, count($nav));
        $this->write($nav, $sections);

        return [
            'nav' => $nav,
            'sections' => $sections,
        ];
    }

    public function invalidate(): void
    {
        if (is_file($this->cachePath)) {
            @unlink($this->cachePath);
        }
    }

    /**
     * @param array<int, array<string, mixed>> $nav
     * @param array<int, array<string, mixed>> $sections
     */
    private function write(array $nav, array $sections): void
    {
        $dir = dirname($this->cachePath);
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }

        $payload = [
            'generated_at' => time(),
            'nav' => $nav,
            'sections' => $sections,
        ];

        $dump = "<?php\n\nreturn " . var_export($payload, true) . ";\n";
        $tmp = $this->cachePath . '.' . bin2hex(random_bytes(4)) . '.tmp';
        file_put_contents($tmp, $dump, LOCK_EX);
        @rename($tmp, $this->cachePath);
    }
}
