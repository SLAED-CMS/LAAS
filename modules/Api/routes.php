<?php
declare(strict_types=1);

return [
    ['GET', '/api/v1/ping', [\Laas\Modules\Api\Controller\PingController::class, 'ping']],
    ['POST', '/api/v1/auth/token', [\Laas\Modules\Api\Controller\AuthController::class, 'token']],
    ['GET', '/api/v1/me', [\Laas\Modules\Api\Controller\AuthController::class, 'me']],
    ['POST', '/api/v1/auth/revoke', [\Laas\Modules\Api\Controller\AuthController::class, 'revoke']],
    ['GET', '/api/v1/pages', [\Laas\Modules\Api\Controller\PagesController::class, 'index']],
    ['GET', '/api/v1/pages/{id:\d+}', [\Laas\Modules\Api\Controller\PagesController::class, 'show']],
    ['GET', '/api/v1/pages/by-slug/{slug:[^/]+}', [\Laas\Modules\Api\Controller\PagesController::class, 'bySlug']],
    ['GET', '/api/v1/media', [\Laas\Modules\Api\Controller\MediaController::class, 'index']],
    ['GET', '/api/v1/media/{id:\d+}', [\Laas\Modules\Api\Controller\MediaController::class, 'show']],
    ['GET', '/api/v1/media/{id:\d+}/download', [\Laas\Modules\Api\Controller\MediaController::class, 'download']],
    ['GET', '/api/v1/menus/{name:[^/]+}', [\Laas\Modules\Api\Controller\MenusController::class, 'show']],
    ['GET', '/api/v1/users', [\Laas\Modules\Api\Controller\UsersController::class, 'index']],
    ['GET', '/api/v1/users/{id:\d+}', [\Laas\Modules\Api\Controller\UsersController::class, 'show']],
];
