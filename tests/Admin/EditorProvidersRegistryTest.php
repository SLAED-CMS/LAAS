<?php
declare(strict_types=1);

use Laas\Admin\Editors\EditorProvidersRegistry;
use Laas\Assets\AssetsManager;
use PHPUnit\Framework\TestCase;

final class EditorProvidersRegistryTest extends TestCase
{
    private ?string $previousAssetBase = null;

    protected function setUp(): void
    {
        $this->previousAssetBase = $_ENV['ASSET_BASE'] ?? null;
        $_ENV['ASSET_BASE'] = '/__test_assets__';
    }

    protected function tearDown(): void
    {
        if ($this->previousAssetBase === null) {
            unset($_ENV['ASSET_BASE']);
        } else {
            $_ENV['ASSET_BASE'] = $this->previousAssetBase;
        }
    }

    public function testRegistryReportsAvailabilityAndTextareaFallback(): void
    {
        $assets = new AssetsManager([]);
        $registry = new EditorProvidersRegistry($assets);

        $caps = $registry->capabilities();
        $this->assertFalse($caps['tinymce']['available']);
        $this->assertFalse($caps['toastui']['available']);
        $this->assertTrue($caps['textarea']['available']);

        $editors = $registry->editors();
        $textarea = null;
        foreach ($editors as $editor) {
            if (($editor['id'] ?? '') === 'textarea') {
                $textarea = $editor;
                break;
            }
        }

        $this->assertNotNull($textarea);
        $this->assertTrue((bool) ($textarea['available'] ?? false));
    }
}
