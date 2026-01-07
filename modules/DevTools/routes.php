<?php
declare(strict_types=1);

return [
    ['GET', '/__devtools/ping', [\Laas\Modules\DevTools\Controller\DevToolsController::class, 'ping']],
    ['GET', '/__devtools/panel', [\Laas\Modules\DevTools\Controller\DevToolsController::class, 'panel']],
    ['POST', '/__devtools/js-errors/collect', [\Laas\Modules\DevTools\Controller\DevToolsController::class, 'jsErrorsCollect']],
    ['GET', '/__devtools/js-errors/list', [\Laas\Modules\DevTools\Controller\DevToolsController::class, 'jsErrorsList']],
    ['POST', '/__devtools/js-errors/clear', [\Laas\Modules\DevTools\Controller\DevToolsController::class, 'jsErrorsClear']],
];
