<?php
declare(strict_types=1);

return [
    ['GET', '/login', [\Laas\Modules\Users\Controller\AuthController::class, 'showLogin']],
    ['POST', '/login', [\Laas\Modules\Users\Controller\AuthController::class, 'doLogin']],
    ['POST', '/logout', [\Laas\Modules\Users\Controller\AuthController::class, 'doLogout']],
];
