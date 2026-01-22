<?php

declare(strict_types=1);

return [
    ['GET', '/changelog', [\Laas\Modules\Changelog\Controller\ChangelogController::class, 'index']],
    ['GET', '/admin/changelog', [\Laas\Modules\Changelog\Controller\AdminChangelogController::class, 'index']],
    ['POST', '/admin/changelog/save', [\Laas\Modules\Changelog\Controller\AdminChangelogController::class, 'save']],
    ['POST', '/admin/changelog/test', [\Laas\Modules\Changelog\Controller\AdminChangelogController::class, 'test']],
    ['POST', '/admin/changelog/cache/clear', [\Laas\Modules\Changelog\Controller\AdminChangelogController::class, 'clearCache']],
    ['GET', '/admin/changelog/preview', [\Laas\Modules\Changelog\Controller\AdminChangelogController::class, 'preview']],
];
