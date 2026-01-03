<?php
declare(strict_types=1);

return [
    ['GET', '/admin/media', [\Laas\Modules\Media\Controller\AdminMediaController::class, 'index']],
    ['GET', '/admin/media/picker', [\Laas\Modules\Media\Controller\AdminMediaPickerController::class, 'index']],
    ['POST', '/admin/media/picker/select', [\Laas\Modules\Media\Controller\AdminMediaPickerController::class, 'select']],
    // rate-limited: media_upload
    ['POST', '/admin/media/upload', [\Laas\Modules\Media\Controller\AdminMediaController::class, 'upload']],
    ['POST', '/admin/media/delete', [\Laas\Modules\Media\Controller\AdminMediaController::class, 'delete']],
    ['POST', '/admin/media/{id:\d+}/public', [\Laas\Modules\Media\Controller\AdminMediaController::class, 'togglePublic']],
    ['GET', '/admin/media/{id:\d+}/signed', [\Laas\Modules\Media\Controller\AdminMediaController::class, 'signed']],
    ['GET', '/media/{id:\d+}/thumb/{variant:[a-z0-9_]+}', [\Laas\Modules\Media\Controller\MediaThumbController::class, 'serve']],
    ['GET', '/media/{id:\d+}/{name:.+}', [\Laas\Modules\Media\Controller\MediaServeController::class, 'serve']],
];
