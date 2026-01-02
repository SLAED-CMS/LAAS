<?php
declare(strict_types=1);

return [
    ['GET', '/admin/media', [\Laas\Modules\Media\Controller\AdminMediaController::class, 'index']],
    ['POST', '/admin/media/upload', [\Laas\Modules\Media\Controller\AdminMediaController::class, 'upload']],
    ['POST', '/admin/media/delete', [\Laas\Modules\Media\Controller\AdminMediaController::class, 'delete']],
    ['GET', '/media/{id:\d+}/{name:.+}', [\Laas\Modules\Media\Controller\MediaServeController::class, 'serve']],
];
