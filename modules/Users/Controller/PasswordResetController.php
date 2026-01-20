<?php
declare(strict_types=1);

namespace Laas\Modules\Users\Controller;

use Laas\Core\Validation\Validator;
use Laas\Core\Validation\ValidationResult;
use Laas\Domain\Users\UsersServiceInterface;
use Laas\Support\Mail\MailerInterface;
use Laas\Security\RateLimiter;
use Laas\Http\Request;
use Laas\Http\Response;
use Laas\View\View;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

final class PasswordResetController
{
    private LoggerInterface $logger;

    public function __construct(
        private View $view,
        private ?UsersServiceInterface $users,
        private MailerInterface $mailer,
        private RateLimiter $rateLimiter,
        private string $rootPath,
        ?LoggerInterface $logger = null
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    public function showRequestForm(Request $request): Response
    {
        return $this->view->render('pages/password_reset_request.html');
    }

    public function requestReset(Request $request): Response
    {
        if ($this->users === null) {
            return new Response('', 503);
        }

        $email = trim((string) ($request->post('email') ?? ''));

        $validator = new Validator();
        $result = $validator->validate([
            'email' => $email,
        ], [
            'email' => ['required', 'email', 'max:255'],
        ], [
            'label_prefix' => 'users',
            'translator' => $this->view->getTranslator(),
        ]);

        if (!$result->isValid()) {
            $messages = $this->resolveErrorMessages($result);
            return $this->view->render('pages/password_reset_request.html', [
                'errors' => $messages,
            ], 422);
        }

        $rateLimit = $this->rateLimiter->hit(
            'password_reset',
            $request->ip(),
            3600,
            10
        );

        if (!$rateLimit['allowed']) {
            $this->logger->warning('Password reset rate limit exceeded', [
                'ip' => $request->ip(),
                'email' => $email,
                'retry_after' => $rateLimit['retry_after'],
            ]);

            $errorMessage = $this->view->translate('users.password_reset.rate_limit_exceeded');
            return $this->view->render('pages/password_reset_request.html', [
                'errors' => [$errorMessage],
            ], 429);
        }

        $user = $this->users->findByEmail($email);

        if ($user !== null && (int) ($user['status'] ?? 0) === 1) {
            $rawToken = bin2hex(random_bytes(32));
            $hashedToken = hash('sha256', $rawToken);

            $this->users->deletePasswordResetByEmail($email);
            $this->users->createPasswordResetToken($email, $hashedToken, 3600);

            $scheme = $request->header('X-Forwarded-Proto') ?? ($request->isSecure() ? 'https' : 'http');
            $host = $request->header('Host') ?? 'localhost';
            $resetUrl = sprintf('%s://%s/password-reset?token=%s', $scheme, $host, urlencode($rawToken));

            $subject = $this->view->translate('users.password_reset.email_subject');
            $body = $this->renderResetEmail($resetUrl, $email);

            $sent = $this->mailer->send($email, $subject, $body);

            if (!$sent) {
                $this->logger->error('Failed to send password reset email', [
                    'email' => $email,
                ]);
            } else {
                $this->logger->info('Password reset email sent', [
                    'email' => $email,
                ]);
            }
        }

        $successMessage = $this->view->translate('users.password_reset.request_success');
        return $this->view->render('pages/password_reset_request.html', [
            'success' => $successMessage,
        ]);
    }

    public function showResetForm(Request $request): Response
    {
        if ($this->users === null) {
            return $this->view->render('pages/password_reset_invalid.html', [], 503);
        }

        $rawToken = trim((string) ($request->query('token') ?? ''));
        if ($rawToken === '') {
            return $this->view->render('pages/password_reset_invalid.html', [], 400);
        }

        $hashedToken = hash('sha256', $rawToken);
        $tokenRecord = $this->users->findPasswordResetByToken($hashedToken);

        if ($tokenRecord === null || !$this->users->isPasswordResetTokenValid($tokenRecord)) {
            return $this->view->render('pages/password_reset_invalid.html', [], 400);
        }

        return $this->view->render('pages/password_reset_form.html', [
            'token' => htmlspecialchars($rawToken, ENT_QUOTES, 'UTF-8'),
        ]);
    }

    public function processReset(Request $request): Response
    {
        if ($this->users === null) {
            return $this->view->render('pages/password_reset_invalid.html', [], 503);
        }

        $rawToken = trim((string) ($request->post('token') ?? ''));
        $password = (string) ($request->post('password') ?? '');
        $passwordConfirm = (string) ($request->post('password_confirm') ?? '');

        $validator = new Validator();
        $result = $validator->validate([
            'token' => $rawToken,
            'password' => $password,
            'password_confirm' => $passwordConfirm,
        ], [
            'token' => ['required', 'string'],
            'password' => ['required', 'string', 'min:8', 'max:255'],
            'password_confirm' => ['required', 'string'],
        ], [
            'label_prefix' => 'users',
            'translator' => $this->view->getTranslator(),
        ]);

        if (!$result->isValid()) {
            $messages = $this->resolveErrorMessages($result);
            return $this->view->render('pages/password_reset_form.html', [
                'errors' => $messages,
                'token' => htmlspecialchars($rawToken, ENT_QUOTES, 'UTF-8'),
            ], 422);
        }

        if ($password !== $passwordConfirm) {
            $errorMessage = $this->view->translate('users.password_reset.passwords_do_not_match');
            return $this->view->render('pages/password_reset_form.html', [
                'errors' => [$errorMessage],
                'token' => htmlspecialchars($rawToken, ENT_QUOTES, 'UTF-8'),
            ], 422);
        }

        if ($rawToken === '') {
            return $this->view->render('pages/password_reset_invalid.html', [], 400);
        }

        $hashedToken = hash('sha256', $rawToken);
        $tokenRecord = $this->users->findPasswordResetByToken($hashedToken);

        if ($tokenRecord === null || !$this->users->isPasswordResetTokenValid($tokenRecord)) {
            return $this->view->render('pages/password_reset_invalid.html', [], 400);
        }

        $email = (string) ($tokenRecord['email'] ?? '');
        $user = $this->users->findByEmail($email);

        if ($user === null || (int) ($user['status'] ?? 0) !== 1) {
            $this->logger->warning('Password reset attempted for invalid user', [
                'email' => $email,
            ]);
            return $this->view->render('pages/password_reset_invalid.html', [], 400);
        }

        $passwordHash = password_hash($password, PASSWORD_BCRYPT);
        $this->users->setPasswordHash((int) $user['id'], $passwordHash);

        $this->users->deletePasswordResetToken($hashedToken);

        $this->logger->info('Password reset successful', [
            'user_id' => (int) $user['id'],
            'email' => $email,
        ]);

        $successMessage = $this->view->translate('users.password_reset.success');
        return $this->view->render('pages/password_reset_success.html', [
            'success' => $successMessage,
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

    private function renderResetEmail(string $resetUrl, string $email): string
    {
        $escapedUrl = htmlspecialchars($resetUrl, ENT_QUOTES, 'UTF-8');
        $escapedEmail = htmlspecialchars($email, ENT_QUOTES, 'UTF-8');

        return <<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Password Reset</title>
</head>
<body>
    <h2>Password Reset Request</h2>
    <p>A password reset was requested for your account ({$escapedEmail}).</p>
    <p>Click the link below to reset your password:</p>
    <p><a href="{$escapedUrl}">{$escapedUrl}</a></p>
    <p>This link will expire in 1 hour.</p>
    <p>If you did not request this password reset, please ignore this email.</p>
</body>
</html>
HTML;
    }
}
