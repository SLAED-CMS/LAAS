<?php
declare(strict_types=1);

return [
    ['GET', '/demoenv/ping', [\Laas\Modules\DemoEnv\Controller\DemoEnvPingController::class, 'ping']],
];
