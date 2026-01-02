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
    ['GET', '/admin/audit', [\Laas\Modules\Admin\Controller\AuditController::class, 'index']],
];
