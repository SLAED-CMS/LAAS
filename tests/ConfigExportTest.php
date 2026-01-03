<?php
declare(strict_types=1);

use Laas\Support\ConfigExporter;
use PHPUnit\Framework\TestCase;

final class ConfigExportTest extends TestCase
{
    public function testExportOmitsSecretsByDefault(): void
    {
        $exporter = new ConfigExporter(
            sys_get_temp_dir(),
            [
                'env' => 'test',
                'debug' => false,
                'read_only' => false,
                'devtools' => ['enabled' => true],
                'theme' => 'default',
                'default_locale' => 'en',
                'version' => 'v2.0.0',
            ],
            [],
            ['default' => 'local', 'disks' => ['s3' => []]],
            ['Laas\\Modules\\Media\\MediaModule'],
            [
                'site_name' => 'LAAS',
                'api_token' => 'secret',
            ],
            null
        );

        $snapshot = $exporter->buildSnapshot(true, []);
        $settings = $snapshot['config']['settings'] ?? [];
        $this->assertSame('[redacted]', $settings['api_token'] ?? null);
        $this->assertSame('LAAS', $settings['site_name'] ?? null);
    }

    public function testExportIncludesStorageNonSecretFields(): void
    {
        $exporter = new ConfigExporter(
            sys_get_temp_dir(),
            ['env' => 'test', 'debug' => false, 'read_only' => false, 'devtools' => ['enabled' => false]],
            [],
            [
                'default' => 's3',
                'disks' => [
                    's3' => [
                        'endpoint' => 'http://127.0.0.1:9000',
                        'region' => 'us-east-1',
                        'bucket' => 'laas',
                        'prefix' => 'laas',
                        'verify_tls' => false,
                        'use_path_style' => true,
                    ],
                ],
            ],
            [],
            [],
            null
        );

        $snapshot = $exporter->buildSnapshot(true, []);
        $storage = $snapshot['config']['storage'] ?? [];
        $this->assertSame('s3', $storage['disk'] ?? null);
        $this->assertSame('http://127.0.0.1:9000', $storage['endpoint'] ?? null);
        $this->assertSame('us-east-1', $storage['region'] ?? null);
        $this->assertSame('laas', $storage['bucket'] ?? null);
        $this->assertSame('laas', $storage['prefix'] ?? null);
        $this->assertFalse((bool) ($storage['verify_tls'] ?? true));
        $this->assertTrue((bool) ($storage['use_path_style'] ?? false));
    }

    public function testOutWritesFileAtomically(): void
    {
        $dir = sys_get_temp_dir() . '/laas_export_' . bin2hex(random_bytes(4));
        mkdir($dir, 0775, true);
        $path = $dir . '/config.json';

        $exporter = new ConfigExporter(
            sys_get_temp_dir(),
            ['env' => 'test', 'debug' => false, 'read_only' => false, 'devtools' => ['enabled' => false]],
            [],
            ['default' => 'local', 'disks' => ['s3' => []]],
            [],
            [],
            null
        );

        $json = "{\n  \"ok\": true\n}\n";
        $result = $exporter->writeAtomic($path, $json);

        $this->assertTrue($result);
        $this->assertSame($json, file_get_contents($path));
        $this->assertSame([], glob($path . '.tmp_*') ?: []);
    }
}
