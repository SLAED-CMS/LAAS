<?php
declare(strict_types=1);

return [
    ['GET', '/admin', [\Laas\Modules\Admin\Controller\DashboardController::class, 'index']],
    ['GET', '/admin/modules', [\Laas\Modules\Admin\Controller\ModulesController::class, 'index']],
    ['POST', '/admin/modules/toggle', [\Laas\Modules\Admin\Controller\ModulesController::class, 'toggle']],
    ['GET', '/admin/settings', [\Laas\Modules\Admin\Controller\SettingsController::class, 'index']],
    ['POST', '/admin/settings', [\Laas\Modules\Admin\Controller\SettingsController::class, 'save']],
    ['GET', '/admin/users', [\Laas\Modules\Admin\Controller\UsersController::class, 'index']],
    ['POST', '/admin/users/status', [\Laas\Modules\Admin\Controller\UsersController::class, 'toggleStatus']],
    ['POST', '/admin/users/admin', [\Laas\Modules\Admin\Controller\UsersController::class, 'toggleAdmin']],
    ['GET', '/admin/users/roles', [\Laas\Modules\Admin\Controller\RolesController::class, 'index']],
    ['GET', '/admin/users/roles/new', [\Laas\Modules\Admin\Controller\RolesController::class, 'createForm']],
    ['GET', '/admin/users/roles/{id:\d+}/edit', [\Laas\Modules\Admin\Controller\RolesController::class, 'editForm']],
    ['POST', '/admin/users/roles/save', [\Laas\Modules\Admin\Controller\RolesController::class, 'save']],
    ['POST', '/admin/users/roles/delete', [\Laas\Modules\Admin\Controller\RolesController::class, 'delete']],
    ['GET', '/admin/users/roles/{id:\d+}/clone', [\Laas\Modules\Admin\Controller\RolesController::class, 'cloneForm']],
    ['POST', '/admin/users/roles/{id:\d+}/clone', [\Laas\Modules\Admin\Controller\RolesController::class, 'clone']],
    ['GET', '/admin/audit', [\Laas\Modules\Admin\Controller\AuditController::class, 'index']],
    ['GET', '/admin/api/tokens', [\Laas\Modules\Admin\Controller\ApiTokensController::class, 'index']],
    ['POST', '/admin/api/tokens', [\Laas\Modules\Admin\Controller\ApiTokensController::class, 'create']],
    ['POST', '/admin/api/tokens/revoke', [\Laas\Modules\Admin\Controller\ApiTokensController::class, 'revoke']],
    ['GET', '/admin/search', [\Laas\Modules\Admin\Controller\AdminSearchController::class, 'index']],
    ['GET', '/admin/rbac/diagnostics', [\Laas\Modules\Admin\Controller\RbacDiagnosticsController::class, 'index']],
    ['POST', '/admin/rbac/diagnostics/check', [\Laas\Modules\Admin\Controller\RbacDiagnosticsController::class, 'checkPermission']],
];
