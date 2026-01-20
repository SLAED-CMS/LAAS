<?php
declare(strict_types=1);

namespace Laas\Modules\Users\Controller;

use Laas\Auth\AuthInterface;
use Laas\Auth\TotpService;
use Laas\Core\Validation\Validator;
use Laas\Core\Validation\ValidationResult;
use Laas\Domain\Users\UsersServiceInterface;
use Laas\Http\Request;
use Laas\Http\Response;
use Laas\View\View;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

final class TwoFactorController
{
    private LoggerInterface $logger;

    public function __construct(
        private View $view,
        private AuthInterface $auth,
        private ?UsersServiceInterface $users,
        private TotpService $totp,
        ?LoggerInterface $logger = null
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    public function showSetup(Request $request): Response
    {
        if ($this->users === null) {
            return new Response('', 503);
        }

        $user = $this->auth->user();
        if ($user === null) {
            return new Response('', 302, ['Location' => '/login']);
        }

        $userId = (int) $user['id'];
        $totpData = $this->users->getTotpData($userId);

        $isEnabled = (int) ($totpData['totp_enabled'] ?? 0) === 1;
        $hasBackupCodes = !empty($totpData['backup_codes']);

        return $this->view->render('pages/2fa_setup.html', [
            'totp_enabled' => $isEnabled,
            'has_backup_codes' => $hasBackupCodes,
        ]);
    }

    public function enableTotp(Request $request): Response
    {
        if ($this->users === null) {
            return new Response('', 503);
        }

        $user = $this->auth->user();
        if ($user === null) {
            return new Response('', 302, ['Location' => '/login']);
        }

        $userId = (int) $user['id'];
        $totpData = $this->users->getTotpData($userId);

        if ((int) ($totpData['totp_enabled'] ?? 0) === 1) {
            $errorMessage = $this->view->translate('users.2fa.already_enabled');
            return $this->view->render('pages/2fa_setup.html', [
                'errors' => [$errorMessage],
                'totp_enabled' => true,
            ], 400);
        }

        $secret = $this->totp->generateSecret();
        $this->users->setTotpSecret($userId, $secret);

        $email = (string) ($user['email'] ?? $user['username']);
        $qrCodeUrl = $this->totp->getQRCodeUrl($secret, $email);

        $session = $request->session();
        $session->set('_totp_setup_secret', $secret);

        return $this->view->render('pages/2fa_enable.html', [
            'secret' => $secret,
            'qr_code_url' => $qrCodeUrl,
        ]);
    }

    public function verifyAndEnable(Request $request): Response
    {
        if ($this->users === null) {
            return new Response('', 503);
        }

        $user = $this->auth->user();
        if ($user === null) {
            return new Response('', 302, ['Location' => '/login']);
        }

        $code = trim((string) ($request->post('code') ?? ''));
        $session = $request->session();
        $setupSecret = (string) $session->get('_totp_setup_secret', '');

        $validator = new Validator();
        $result = $validator->validate([
            'code' => $code,
        ], [
            'code' => ['required', 'string', 'min:6', 'max:6'],
        ], [
            'label_prefix' => 'users',
            'translator' => $this->view->getTranslator(),
        ]);

        if (!$result->isValid()) {
            $messages = $this->resolveErrorMessages($result);
            return $this->view->render('pages/2fa_enable.html', [
                'errors' => $messages,
                'secret' => $setupSecret,
                'qr_code_url' => $this->totp->getQRCodeUrl($setupSecret, (string) ($user['email'] ?? $user['username'])),
            ], 422);
        }

        if ($setupSecret === '') {
            $errorMessage = $this->view->translate('users.2fa.setup_expired');
            return $this->view->render('pages/2fa_setup.html', [
                'errors' => [$errorMessage],
            ], 400);
        }

        if (!$this->totp->verifyCode($setupSecret, $code)) {
            $errorMessage = $this->view->translate('users.2fa.invalid_code');
            return $this->view->render('pages/2fa_enable.html', [
                'errors' => [$errorMessage],
                'secret' => $setupSecret,
                'qr_code_url' => $this->totp->getQRCodeUrl($setupSecret, (string) ($user['email'] ?? $user['username'])),
            ], 422);
        }

        $userId = (int) $user['id'];
        $backupCodes = $this->totp->generateBackupCodes(10);
        $this->users->setBackupCodes($userId, json_encode($backupCodes));
        $this->users->setTotpEnabled($userId, true);

        $session->delete('_totp_setup_secret');

        $this->logger->info('2FA enabled for user', [
            'user_id' => $userId,
        ]);

        return $this->view->render('pages/2fa_backup_codes.html', [
            'backup_codes' => $backupCodes,
        ]);
    }

    public function disable(Request $request): Response
    {
        if ($this->users === null) {
            return new Response('', 503);
        }

        $user = $this->auth->user();
        if ($user === null) {
            return new Response('', 302, ['Location' => '/login']);
        }

        $userId = (int) $user['id'];
        $password = (string) ($request->post('password') ?? '');

        $validator = new Validator();
        $result = $validator->validate([
            'password' => $password,
        ], [
            'password' => ['required', 'string'],
        ], [
            'label_prefix' => 'users',
            'translator' => $this->view->getTranslator(),
        ]);

        if (!$result->isValid()) {
            $messages = $this->resolveErrorMessages($result);
            return $this->view->render('pages/2fa_setup.html', [
                'errors' => $messages,
                'totp_enabled' => true,
            ], 422);
        }

        $hash = (string) ($user['password_hash'] ?? '');
        if (!password_verify($password, $hash)) {
            $errorMessage = $this->view->translate('users.2fa.invalid_password');
            return $this->view->render('pages/2fa_setup.html', [
                'errors' => [$errorMessage],
                'totp_enabled' => true,
            ], 422);
        }

        $this->users->setTotpSecret($userId, null);
        $this->users->setTotpEnabled($userId, false);
        $this->users->setBackupCodes($userId, null);

        $this->logger->info('2FA disabled for user', [
            'user_id' => $userId,
        ]);

        $successMessage = $this->view->translate('users.2fa.disabled_success');
        return $this->view->render('pages/2fa_setup.html', [
            'success' => $successMessage,
            'totp_enabled' => false,
        ]);
    }

    public function regenerateBackupCodes(Request $request): Response
    {
        if ($this->users === null) {
            return new Response('', 503);
        }

        $user = $this->auth->user();
        if ($user === null) {
            return new Response('', 302, ['Location' => '/login']);
        }

        $userId = (int) $user['id'];
        $totpData = $this->users->getTotpData($userId);

        if ((int) ($totpData['totp_enabled'] ?? 0) !== 1) {
            $errorMessage = $this->view->translate('users.2fa.not_enabled');
            return $this->view->render('pages/2fa_setup.html', [
                'errors' => [$errorMessage],
            ], 400);
        }

        $backupCodes = $this->totp->generateBackupCodes(10);
        $this->users->setBackupCodes($userId, json_encode($backupCodes));

        $this->logger->info('Backup codes regenerated for user', [
            'user_id' => $userId,
        ]);

        return $this->view->render('pages/2fa_backup_codes.html', [
            'backup_codes' => $backupCodes,
            'regenerated' => true,
        ]);
    }

    /** @return array<int, string> */
    private function resolveErrorMessages(ValidationResult $errors): array
    {
        $messages = [];
        foreach ($errors->errors() as $fieldErrors) {
            foreach ($fieldErrors as $error) {
                $messages[] = $this->view->translate((string) $error['key'], $error['params'] ?? []);
            }
        }

        return $messages;
    }
}
