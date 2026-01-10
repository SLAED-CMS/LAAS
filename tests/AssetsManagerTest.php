<?php
declare(strict_types=1);

use Laas\Assets\AssetsManager;
use PHPUnit\Framework\TestCase;

final class AssetsManagerTest extends TestCase
{
    public function testBuildsUrlsFromEnvAndNormalizesSlashes(): void
    {
        $backup = $_ENV;
        try {
            $_ENV['ASSET_BASE'] = '/assets/';
            $_ENV['ASSET_VENDOR_BASE'] = '/assets//vendor/';
            $_ENV['ASSET_APP_BASE'] = '/assets/app/';
            $_ENV['ASSET_BOOTSTRAP_VERSION'] = '5.3.3';
            $_ENV['ASSET_HTMX_VERSION'] = '1.9.12';
            $_ENV['ASSET_BOOTSTRAP_ICONS_VERSION'] = '1.11.3';

            $manager = new AssetsManager([]);
            $assets = $manager->all();

            $this->assertSame('/assets/vendor/bootstrap/5.3.3/bootstrap.min.css', $assets['bootstrap_css']);
            $this->assertSame('/assets/vendor/bootstrap/5.3.3/bootstrap.bundle.min.js', $assets['bootstrap_js']);
            $this->assertSame('/assets/vendor/bootstrap-icons/1.11.3/bootstrap-icons.css', $assets['bootstrap_icons_css']);
            $this->assertSame('/assets/vendor/htmx/1.9.12/htmx.min.js', $assets['htmx_js']);
            $this->assertSame('/assets/app/app.css', $assets['app_css']);
            $this->assertSame('/assets/app/app.js', $assets['app_js']);
        } finally {
            $_ENV = $backup;
        }
    }
}
