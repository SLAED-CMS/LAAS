<?php
declare(strict_types=1);

require_once __DIR__ . '/ModuleContractTestCase.php';

final class ModuleDiscoveryContractTest extends ModuleContractTestCase
{
    public function testModuleJsonSchemaAndFiles(): void
    {
        foreach ($this->moduleDescriptors() as $module) {
            $dir = $module['dir'];
            $name = $module['name'];
            $meta = $module['meta'];

            $this->assertArrayHasKey('name', $meta, $name . ': module.json missing name');
            $this->assertArrayHasKey('type', $meta, $name . ': module.json missing type');
            $this->assertArrayHasKey('version', $meta, $name . ': module.json missing version');
            $this->assertArrayHasKey('description', $meta, $name . ': module.json missing description');

            $this->assertIsString($meta['name']);
            $this->assertIsString($meta['type']);
            $this->assertIsString($meta['version']);
            $this->assertIsString($meta['description']);

            $moduleClass = $dir . '/' . $name . 'Module.php';
            $this->assertFileExists($moduleClass, $name . ': missing module class');
            $this->assertFileExists($dir . '/routes.php', $name . ': missing routes.php');

            $langDir = $dir . '/lang';
            if (is_dir($langDir)) {
                $this->assertFileExists($langDir . '/en.php', $name . ': missing lang/en.php');
            }
        }
    }
}
