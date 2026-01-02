<?php
declare(strict_types=1);

return [
    ['GET', '/__devtools/ping', [\Laas\Modules\DevTools\Controller\DevToolsController::class, 'ping']],
    ['GET', '/__devtools/panel', [\Laas\Modules\DevTools\Controller\DevToolsController::class, 'panel']],
];
