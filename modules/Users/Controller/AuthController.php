<?php
declare(strict_types=1);

namespace Laas\Modules\Users\Controller;

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

        if ($this->auth->attempt($username, $password, $request->ip())) {
            return new Response('', 302, [
                'Location' => '/admin',
            ]);
        }

        return $this->view->render('pages/login.html', [
            'error_key' => 'users.login.invalid',
        ]);
    }

    public function doLogout(Request $request): Response
    {
        $this->auth->logout();

        return new Response('', 302, [
            'Location' => '/',
        ]);
    }
}
