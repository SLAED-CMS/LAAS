<?php
declare(strict_types=1);

return [
    ['GET', '/{slug:[a-z0-9-]+}', [\Laas\Modules\Pages\Controller\PagesController::class, 'show']],
];
