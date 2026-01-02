<?php
declare(strict_types=1);

namespace Laas\Modules\Users\Controller;

use Laas\Core\Validation\Validator;
use Laas\Core\Validation\ValidationResult;
use Laas\Auth\AuthInterface;
use Laas\Http\Request;
use Laas\Http\Response;
use Laas\View\View;

final class AuthController
{
    public function __construct(
        private View $view,
        private AuthInterface $auth
    ) {
    }

    public function showLogin(Request $request): Response
    {
        return $this->view->render('pages/login.html');
    }

    public function doLogin(Request $request): Response
    {
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

        if ($this->auth->attempt($username, $password, $request->ip())) {
            return new Response('', 302, [
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
        ]);
    }

    public function doLogout(Request $request): Response
    {
        $this->auth->logout();

        return new Response('', 302, [
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
