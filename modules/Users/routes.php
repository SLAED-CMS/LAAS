<?php
declare(strict_types=1);

return [
    ['GET', '/login', [\Laas\Modules\Users\Controller\AuthController::class, 'showLogin']],
    ['POST', '/login', [\Laas\Modules\Users\Controller\AuthController::class, 'doLogin']],
    ['POST', '/logout', [\Laas\Modules\Users\Controller\AuthController::class, 'doLogout']],

    ['GET', '/password-reset/request', [\Laas\Modules\Users\Controller\PasswordResetController::class, 'showRequestForm']],
    ['POST', '/password-reset/request', [\Laas\Modules\Users\Controller\PasswordResetController::class, 'requestReset']],
    ['GET', '/password-reset', [\Laas\Modules\Users\Controller\PasswordResetController::class, 'showResetForm']],
    ['POST', '/password-reset/process', [\Laas\Modules\Users\Controller\PasswordResetController::class, 'processReset']],

    ['GET', '/2fa/verify', [\Laas\Modules\Users\Controller\AuthController::class, 'show2faVerify']],
    ['POST', '/2fa/verify', [\Laas\Modules\Users\Controller\AuthController::class, 'verify2fa']],

    ['GET', '/2fa/setup', [\Laas\Modules\Users\Controller\TwoFactorController::class, 'showSetup']],
    ['POST', '/2fa/enable', [\Laas\Modules\Users\Controller\TwoFactorController::class, 'enableTotp']],
    ['POST', '/2fa/verify-and-enable', [\Laas\Modules\Users\Controller\TwoFactorController::class, 'verifyAndEnable']],
    ['POST', '/2fa/disable', [\Laas\Modules\Users\Controller\TwoFactorController::class, 'disable']],
    ['GET', '/2fa/regenerate-backup-codes', [\Laas\Modules\Users\Controller\TwoFactorController::class, 'regenerateBackupCodes']],
];
