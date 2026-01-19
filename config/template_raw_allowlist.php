<?php

declare(strict_types=1);

/**
 * Approved raw usages in themes.
 *
 * This allowlist documents intentionally unescaped template outputs.
 * Each entry represents a {% raw %} usage that has been security-reviewed.
 *
 * Update via: php tools/cli.php templates:raw:check --update
 */
return [
    'version' => 1,
    'items' => [
        ['file' => 'themes/admin/pages/audit.html', 'line' => 36, 'kind' => 'raw_output'],
        ['file' => 'themes/admin/pages/page_form.html', 'line' => 45, 'kind' => 'raw_output'],
        ['file' => 'themes/admin/pages/page_form.html', 'line' => 46, 'kind' => 'raw_output'],
        ['file' => 'themes/admin/pages/pages.html', 'line' => 29, 'kind' => 'raw_output'],
        ['file' => 'themes/admin/pages/pages.html', 'line' => 30, 'kind' => 'raw_output'],
        ['file' => 'themes/admin/pages/pages.html', 'line' => 31, 'kind' => 'raw_output'],
        ['file' => 'themes/admin/partials/audit_table.html', 'line' => 22, 'kind' => 'raw_output'],
        ['file' => 'themes/admin/partials/audit_table.html', 'line' => 42, 'kind' => 'raw_output'],
        ['file' => 'themes/admin/partials/audit_table.html', 'line' => 42, 'kind' => 'raw_output'],
        ['file' => 'themes/admin/partials/menu_item_form.html', 'line' => 26, 'kind' => 'raw_output'],
        ['file' => 'themes/admin/partials/menu_item_form.html', 'line' => 34, 'kind' => 'raw_output'],
        ['file' => 'themes/admin/partials/settings_form.html', 'line' => 28, 'kind' => 'raw_output'],
        ['file' => 'themes/admin/partials/settings_form.html', 'line' => 41, 'kind' => 'raw_output'],
        ['file' => 'themes/admin/partials/settings_form.html', 'line' => 54, 'kind' => 'raw_output'],
        ['file' => 'themes/default/pages/page.html', 'line' => 10, 'kind' => 'raw_output'],
        ['file' => 'themes/default/pages/page.html', 'line' => 14, 'kind' => 'raw_output'],
    ],
];
