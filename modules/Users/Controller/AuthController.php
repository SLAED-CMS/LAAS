<?php
declare(strict_types=1);

namespace Laas\Modules\Users\Controller;

use Laas\Core\Validation\Validator;
use Laas\Core\Validation\ValidationResult;
use Laas\Auth\AuthInterface;
use Laas\Auth\TotpService;
use Laas\Domain\Users\UsersReadServiceInterface;
use Laas\Domain\Users\UsersWriteServiceInterface;
use Laas\Http\Request;
use Laas\Http\Response;
use Laas\View\View;

final class AuthController
{
    public function __construct(
        private View $view,
        private AuthInterface $auth,
        private ?UsersReadServiceInterface $usersRead,
        private ?UsersWriteServiceInterface $usersWrite,
        private TotpService $totp
    ) {
    }

    public function showLogin(Request $request): Response
    {
        return $this->view->render('pages/login.html');
    }

    public function doLogin(Request $request): Response
    {
        if ($this->usersRead === null || $this->usersWrite === null) {
            return new Response('', 503);
        }

        $username = $request->post('username') ?? '';
        $password = $request->post('password') ?? '';

        $validator = new Validator();
        $result = $validator->validate([
            'username' => $username,
            'password' => $password,
        ], [
            'username' => ['required', 'string', 'max:50'],
            'password' => ['required', 'string', 'max:255'],
        ], [
            'label_prefix' => 'auth',
            'translator' => $this->view->getTranslator(),
        ]);

        if (!$result->isValid()) {
            $messages = $this->resolveErrorMessages($result);
            if ($request->isHtmx()) {
                return $this->view->render('partials/login_messages.html', [
                    'errors' => $messages,
                ], 422);
            }

            return $this->view->render('pages/login.html', [
                'errors' => $messages,
            ], 422);
        }

        $user = $this->usersRead->findByUsername($username);
        if ($user === null || (int) ($user['status'] ?? 0) !== 1) {
            $errorMessage = $this->view->translate('users.login.invalid');
            $errors = [$errorMessage];
            if ($request->isHtmx()) {
                return $this->view->render('partials/login_messages.html', [
                    'errors' => $errors,
                ], 422);
            }

            return $this->view->render('pages/login.html', [
                'errors' => $errors,
            ], 422);
        }

        $hash = (string) ($user['password_hash'] ?? '');
        if (!password_verify($password, $hash)) {
            $errorMessage = $this->view->translate('users.login.invalid');
            $errors = [$errorMessage];
            if ($request->isHtmx()) {
                return $this->view->render('partials/login_messages.html', [
                    'errors' => $errors,
                ], 422);
            }

            return $this->view->render('pages/login.html', [
                'errors' => $errors,
            ], 422);
        }

        $totpData = $this->usersRead->getTotpData((int) $user['id']);
        $totpEnabled = (int) ($totpData['totp_enabled'] ?? 0) === 1;

        if ($totpEnabled) {
            $session = $request->session();
            $session->set('_2fa_pending_user_id', (int) $user['id']);
            $session->set('_2fa_pending_ip', $request->ip());

            return new Response('', 303, [
                'Location' => '/2fa/verify',
            ]);
        }

        if ($this->auth->attempt($username, $password, $request->ip())) {
            return new Response('', 303, [
                'Location' => '/admin',
            ]);
        }

        $errorMessage = $this->view->translate('users.login.invalid');
        $errors = [$errorMessage];
        if ($request->isHtmx()) {
            return $this->view->render('partials/login_messages.html', [
                'errors' => $errors,
            ], 422);
        }

        return $this->view->render('pages/login.html', [
            'errors' => $errors,
        ], 422);
    }

    public function show2faVerify(Request $request): Response
    {
        $session = $request->session();
        $pendingUserId = (int) $session->get('_2fa_pending_user_id', 0);

        if ($pendingUserId === 0) {
            return new Response('', 302, ['Location' => '/login']);
        }

        return $this->view->render('pages/2fa_verify.html');
    }

    public function verify2fa(Request $request): Response
    {
        if ($this->usersRead === null || $this->usersWrite === null) {
            return new Response('', 503);
        }

        $session = $request->session();
        $pendingUserId = (int) $session->get('_2fa_pending_user_id', 0);
        $pendingIp = (string) $session->get('_2fa_pending_ip', '');

        if ($pendingUserId === 0) {
            return new Response('', 302, ['Location' => '/login']);
        }

        $code = trim((string) ($request->post('code') ?? ''));

        $validator = new Validator();
        $result = $validator->validate([
            'code' => $code,
        ], [
            'code' => ['required', 'string'],
        ], [
            'label_prefix' => 'users',
            'translator' => $this->view->getTranslator(),
        ]);

        if (!$result->isValid()) {
            $messages = $this->resolveErrorMessages($result);
            return $this->view->render('pages/2fa_verify.html', [
                'errors' => $messages,
            ], 422);
        }

        $user = $this->usersRead->find($pendingUserId);
        if ($user === null || (int) ($user['status'] ?? 0) !== 1) {
            $session->delete('_2fa_pending_user_id');
            $session->delete('_2fa_pending_ip');
            return new Response('', 302, ['Location' => '/login']);
        }

        $totpData = $this->usersRead->getTotpData($pendingUserId);
        $totpSecret = (string) ($totpData['totp_secret'] ?? '');
        $backupCodesJson = (string) ($totpData['backup_codes'] ?? '');

        $isValidTotpCode = $totpSecret !== '' && $this->totp->verifyCode($totpSecret, $code);

        $backupCodes = [];
        if ($backupCodesJson !== '') {
            $decoded = json_decode($backupCodesJson, true);
            $backupCodes = is_array($decoded) ? $decoded : [];
        }

        $isValidBackupCode = false;
        if (!$isValidTotpCode && count($backupCodes) > 0) {
            $isValidBackupCode = $this->totp->verifyBackupCode($code, $backupCodes);
            if ($isValidBackupCode) {
                $remainingCodes = $this->totp->removeBackupCode($code, $backupCodes);
                $this->usersWrite->setBackupCodes($pendingUserId, json_encode($remainingCodes));
            }
        }

        if (!$isValidTotpCode && !$isValidBackupCode) {
            $errorMessage = $this->view->translate('users.2fa.invalid_code');
            return $this->view->render('pages/2fa_verify.html', [
                'errors' => [$errorMessage],
            ], 422);
        }

        $session->delete('_2fa_pending_user_id');
        $session->delete('_2fa_pending_ip');

        $session->regenerateId(true);
        $session->set('user_id', $pendingUserId);
        $this->usersWrite->updateLoginMeta($pendingUserId, $pendingIp);

        return new Response('', 303, [
            'Location' => '/admin',
        ]);
    }

    public function doLogout(Request $request): Response
    {
        $this->auth->logout();

        return new Response('', 303, [
            'Location' => '/',
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
