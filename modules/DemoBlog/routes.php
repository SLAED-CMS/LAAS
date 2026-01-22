<?php

declare(strict_types=1);

return [
    ['GET', '/demoblog/ping', [\Laas\Modules\DemoBlog\Controller\DemoBlogPingController::class, 'ping']],
];
