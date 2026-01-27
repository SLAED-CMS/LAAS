<?php

declare(strict_types=1);

namespace Laas\Modules;

use Laas\Database\DatabaseManager;
use Laas\Database\Repositories\ModulesRepository;

final class ModulesSnapshot
{
    private string $cachePath;
    private int $ttl;
    private ?DatabaseManager $db;

    public function __construct(string $cachePath, int $ttl = 300, ?DatabaseManager $db = null)
    {
        $this->cachePath = $cachePath;
        $this->ttl = $ttl > 0 ? $ttl : 300;
        $this->db = $db;
    }

    /** @return list<string> */
    public function load(): array
    {
        if (!is_file($this->cachePath)) {
            \Laas\DevTools\ModulesDiscoveryStats::recordMeta('modules_snapshot', 'miss');
            return [];
        }

        $data = @include $this->cachePath;
        if (!is_array($data)) {
            \Laas\DevTools\ModulesDiscoveryStats::recordMeta('modules_snapshot', 'miss');
            return [];
        }

        $generatedAt = (int) ($data['generated_at'] ?? 0);
        if ($generatedAt <= 0) {
            \Laas\DevTools\ModulesDiscoveryStats::recordMeta('modules_snapshot', 'miss');
            return [];
        }
        if (time() - $generatedAt > $this->ttl) {
            \Laas\DevTools\ModulesDiscoveryStats::recordMeta('modules_snapshot', 'miss');
            return [];
        }

        $modules = $data['modules'] ?? [];
        if (!is_array($modules)) {
            \Laas\DevTools\ModulesDiscoveryStats::recordMeta('modules_snapshot', 'miss');
            return [];
        }

        $list = array_values(array_filter($modules, 'is_string'));
        \Laas\DevTools\ModulesDiscoveryStats::recordMeta('modules_snapshot', 'hit');
        return $list;
    }

    /** @return list<string> */
    public function rebuild(): array
    {
        $modules = [];
        if ($this->db !== null) {
            try {
                if ($this->db->healthCheck()) {
                    $repo = new ModulesRepository($this->db->pdo());
                    $all = $repo->all();
                    foreach ($all as $name => $row) {
                        if (!empty($row['enabled'])) {
                            $modules[] = $name;
                        }
                    }
                }
            } catch (\Throwable) {
                $modules = [];
            }
        }

        $this->write($modules);
        \Laas\DevTools\ModulesDiscoveryStats::recordMeta('modules_snapshot', 'rebuild');

        return $modules;
    }

    public function invalidate(): void
    {
        if (is_file($this->cachePath)) {
            @unlink($this->cachePath);
        }
    }

    /**
     * @param list<string> $modules
     */
    private function write(array $modules): void
    {
        $dir = dirname($this->cachePath);
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }

        $payload = [
            'generated_at' => time(),
            'modules' => array_values(array_filter($modules, 'is_string')),
        ];

        $dump = "<?php\n\nreturn " . var_export($payload, true) . ";\n";
        $tmp = $this->cachePath . '.' . bin2hex(random_bytes(4)) . '.tmp';
        file_put_contents($tmp, $dump, LOCK_EX);
        @rename($tmp, $this->cachePath);
    }
}
