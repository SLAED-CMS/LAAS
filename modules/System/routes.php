<?php
declare(strict_types=1);

return [
    ['GET', '/csrf', [\Laas\Modules\System\Controller\CsrfController::class, 'get']],
    ['POST', '/echo', [\Laas\Modules\System\Controller\EchoController::class, 'post']],
    ['GET', '/health', [\Laas\Modules\System\Controller\HealthController::class, 'index']],
    ['POST', '/__csp/report', [\Laas\Modules\System\Controller\CspReportController::class, 'report']],
];
