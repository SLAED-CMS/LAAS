<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

abstract class ModuleContractTestCase extends TestCase
{
    protected function modulesRoot(): string
    {
        return dirname(__DIR__, 2) . '/modules';
    }

    /** @return array<int, array{dir: string, name: string, meta: array}> */
    protected function moduleDescriptors(): array
    {
        $root = $this->modulesRoot();
        $entries = [];
        foreach (glob($root . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
            $metaPath = $dir . '/module.json';
            if (!is_file($metaPath)) {
                continue;
            }
            $raw = file_get_contents($metaPath);
            $meta = is_string($raw) ? json_decode($raw, true) : null;
            if (!is_array($meta)) {
                $meta = [];
            }
            $entries[] = [
                'dir' => $dir,
                'name' => basename($dir),
                'meta' => $meta,
            ];
        }

        return $entries;
    }
}
