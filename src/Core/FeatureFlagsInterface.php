<?php
declare(strict_types=1);

namespace Laas\Core;

interface FeatureFlagsInterface
{
    public const ADMIN_FEATURE_PALETTE = 'ADMIN_FEATURE_PALETTE';
    public const ADMIN_FEATURE_BLOCKS_STUDIO = 'ADMIN_FEATURE_BLOCKS_STUDIO';
    public const ADMIN_FEATURE_THEME_INSPECTOR = 'ADMIN_FEATURE_THEME_INSPECTOR';
    public const ADMIN_FEATURE_HEADLESS_PLAYGROUND = 'ADMIN_FEATURE_HEADLESS_PLAYGROUND';

    public function isEnabled(string $flag): bool;
}
