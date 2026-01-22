<?php

declare(strict_types=1);

return [
    ['GET', '/admin/pages', [\Laas\Modules\Pages\Controller\AdminPagesController::class, 'index']],
    ['GET', '/admin/pages/new', [\Laas\Modules\Pages\Controller\AdminPagesController::class, 'createForm']],
    ['GET', '/admin/pages/{id:\d+}/edit', [\Laas\Modules\Pages\Controller\AdminPagesController::class, 'editForm']],
    ['POST', '/admin/pages/save', [\Laas\Modules\Pages\Controller\AdminPagesController::class, 'save']],
    ['POST', '/admin/pages/preview-blocks', [\Laas\Modules\Pages\Controller\AdminPagesController::class, 'previewBlocks']],
    ['POST', '/admin/pages/status', [\Laas\Modules\Pages\Controller\AdminPagesController::class, 'toggleStatus']],
    ['POST', '/admin/pages/delete', [\Laas\Modules\Pages\Controller\AdminPagesController::class, 'delete']],
    ['GET', '/search', [\Laas\Modules\Pages\Controller\PagesController::class, 'search']],
    ['GET', '/{slug:[a-z0-9-]+}', [\Laas\Modules\Pages\Controller\PagesController::class, 'show']],
];
