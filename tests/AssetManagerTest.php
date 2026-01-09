<?php
declare(strict_types=1);

use Laas\View\AssetManager;
use PHPUnit\Framework\TestCase;

final class AssetManagerTest extends TestCase
{
    public function testBuildCssBuildsLinkWithCacheBusting(): void
    {
        $manager = new AssetManager([
            'base_url' => '/assets',
            'version' => 'v1',
            'cache_busting' => true,
            'css' => [
                'bootstrap' => ['path' => 'vendor/bootstrap/bootstrap.min.css'],
            ],
        ]);

        $html = $manager->buildCss('bootstrap');
        $this->assertSame(
            '<link rel="stylesheet" href="/assets/vendor/bootstrap/bootstrap.min.css?v=v1">',
            $html
        );
    }

    public function testBuildJsAddsDeferAttribute(): void
    {
        $manager = new AssetManager([
            'base_url' => '/assets',
            'version' => 'v2',
            'cache_busting' => true,
            'js' => [
                'htmx' => ['path' => 'vendor/htmx/htmx.min.js', 'defer' => true],
            ],
        ]);

        $html = $manager->buildJs('htmx');
        $this->assertSame(
            '<script src="/assets/vendor/htmx/htmx.min.js?v=v2" defer></script>',
            $html
        );
    }

    public function testCacheBustingCanBeDisabled(): void
    {
        $manager = new AssetManager([
            'base_url' => '/assets',
            'version' => 'v3',
            'cache_busting' => false,
            'css' => [
                'app' => ['path' => 'app/app.css'],
            ],
        ]);

        $html = $manager->buildCss('app');
        $this->assertSame(
            '<link rel="stylesheet" href="/assets/app/app.css">',
            $html
        );
    }
}
