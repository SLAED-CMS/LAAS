<?php
declare(strict_types=1);

return [
    ['GET', '/csrf', [\Laas\Modules\System\Controller\CsrfController::class, 'get']],
    ['POST', '/echo', [\Laas\Modules\System\Controller\EchoController::class, 'post']],
];
