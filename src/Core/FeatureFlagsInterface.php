<?php
declare(strict_types=1);

namespace Laas\Core;

interface FeatureFlagsInterface
{
    public const DEVTOOLS_PALETTE = 'devtools_palette';
    public const DEVTOOLS_BLOCKS_STUDIO = 'devtools_blocks_studio';
    public const DEVTOOLS_THEME_INSPECTOR = 'devtools_theme_inspector';
    public const DEVTOOLS_HEADLESS_PLAYGROUND = 'devtools_headless_playground';

    public const ADMIN_FEATURE_PALETTE = self::DEVTOOLS_PALETTE;
    public const ADMIN_FEATURE_BLOCKS_STUDIO = self::DEVTOOLS_BLOCKS_STUDIO;
    public const ADMIN_FEATURE_THEME_INSPECTOR = self::DEVTOOLS_THEME_INSPECTOR;
    public const ADMIN_FEATURE_HEADLESS_PLAYGROUND = self::DEVTOOLS_HEADLESS_PLAYGROUND;

    public function isEnabled(string $flag): bool;
}
