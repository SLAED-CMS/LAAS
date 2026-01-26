<?php
declare(strict_types=1);

$env = $_ENV;
$envString = static function (string $key, string $default) use ($env): string {
    $value = $env[$key] ?? null;
    if ($value === null || $value === '') {
        return $default;
    }
    return (string) $value;
};
$envBool = static function (string $key, bool $default) use ($env): bool {
    $value = $env[$key] ?? null;
    if ($value === null || $value === '') {
        return $default;
    }
    $parsed = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    return $parsed ?? $default;
};

$assetBase = $envString('ASSET_BASE', $envString('ASSETS_BASE_URL', '/assets'));
$assetBase = rtrim($assetBase, '/');
$assetVendorBase = $envString('ASSET_VENDOR_BASE', $assetBase . '/vendor');
$assetAppBase = $envString('ASSET_APP_BASE', $assetBase . '/app');

$bootstrapVersion = $envString('ASSET_BOOTSTRAP_VERSION', '5.3.3');
$htmxVersion = $envString('ASSET_HTMX_VERSION', '1.9.12');
$bootstrapIconsVersion = $envString('ASSET_BOOTSTRAP_ICONS_VERSION', '1.11.3');

return [
    'base_url' => $assetBase,
    'version' => $envString('ASSETS_VERSION', $envString('APP_VERSION', '')),
    'cache_busting' => $envBool('ASSETS_CACHE_BUSTING', true),
    'asset_base' => $assetBase,
    'asset_vendor_base' => $assetVendorBase,
    'asset_app_base' => $assetAppBase,
    'versions' => [
        'bootstrap' => $bootstrapVersion,
        'htmx' => $htmxVersion,
        'bootstrap_icons' => $bootstrapIconsVersion,
    ],
    'bootstrap_css' => $assetVendorBase . '/bootstrap/' . $bootstrapVersion . '/bootstrap.min.css',
    'bootstrap_js' => $assetVendorBase . '/bootstrap/' . $bootstrapVersion . '/bootstrap.bundle.min.js',
    'bootstrap_icons_css' => $assetVendorBase . '/bootstrap-icons/' . $bootstrapIconsVersion . '/bootstrap-icons.min.css',
    'htmx_js' => $assetVendorBase . '/htmx/' . $htmxVersion . '/htmx.min.js',
    'tinymce_js' => $assetVendorBase . '/tinymce/tinymce.min.js',
    'toastui_editor_css' => $assetVendorBase . '/toastui-editor/toastui-editor.min.css',
    'toastui_editor_js' => $assetVendorBase . '/toastui-editor/toastui-editor.min.js',
    'app_css' => $assetAppBase . '/app.css',
    'app_js' => $assetAppBase . '/app.js',
    'devtools_css' => $assetAppBase . '/devtools.css',
    'devtools_js' => $assetAppBase . '/devtools.js',
    'admin_css' => $assetBase . '/admin.css',
    'admin_js' => $assetBase . '/admin.js',
    'pages_admin_editors_js' => $assetBase . '/admin-pages-editors.js',
    'css' => [
        'bootstrap' => [
            'path' => 'vendor/bootstrap/' . $bootstrapVersion . '/bootstrap.min.css',
        ],
        'bootstrap-icons' => [
            'path' => 'vendor/bootstrap-icons/' . $bootstrapIconsVersion . '/bootstrap-icons.css',
        ],
        'toastui-editor' => [
            'path' => 'vendor/toastui-editor/toastui-editor.min.css',
        ],
        'app' => [
            'path' => 'app/app.css',
        ],
        'devtools' => [
            'path' => 'app/devtools.css',
        ],
        'admin' => [
            'path' => 'admin.css',
        ],
    ],
    'js' => [
        'htmx' => [
            'path' => 'vendor/htmx/' . $htmxVersion . '/htmx.min.js',
            'defer' => true,
        ],
        'bootstrap' => [
            'path' => 'vendor/bootstrap/' . $bootstrapVersion . '/bootstrap.bundle.min.js',
            'defer' => true,
        ],
        'tinymce' => [
            'path' => 'vendor/tinymce/tinymce.min.js',
            'defer' => true,
        ],
        'toastui-editor' => [
            'path' => 'vendor/toastui-editor/toastui-editor.min.js',
            'defer' => true,
        ],
        'app' => [
            'path' => 'app/app.js',
            'defer' => true,
        ],
        'devtools' => [
            'path' => 'app/devtools.js',
            'defer' => true,
        ],
        'admin' => [
            'path' => 'admin.js',
            'defer' => true,
        ],
        'pages-admin-editors' => [
            'path' => 'admin-pages-editors.js',
            'defer' => true,
        ],
    ],
];
