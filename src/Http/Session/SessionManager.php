<?php
declare(strict_types=1);

namespace Laas\Http\Session;

use Laas\Http\Request;
use RuntimeException;

final class SessionManager
{
    public function __construct(
        private string $rootPath,
        private array $config
    ) {
    }

    public function start(?Request $request = null): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        $savePath = $this->rootPath . '/storage/sessions';
        if (!is_dir($savePath) && !mkdir($savePath, 0775, true) && !is_dir($savePath)) {
            throw new RuntimeException('Unable to create session directory: ' . $savePath);
        }

        ini_set('session.save_path', $savePath);
        ini_set('session.use_strict_mode', '1');

        $cookie = $this->config['session'] ?? [];
        $name = $cookie['name'] ?? 'LAASID';

        session_name($name);
        $secure = (bool) ($cookie['secure'] ?? false);
        if ($request !== null && $request->isHttps()) {
            $secure = true;
        }

        session_set_cookie_params([
            'lifetime' => $cookie['lifetime'] ?? 0,
            'path' => '/',
            'domain' => $cookie['domain'] ?? '',
            'secure' => $secure,
            'httponly' => (bool) ($cookie['httponly'] ?? true),
            'samesite' => $cookie['samesite'] ?? 'Lax',
        ]);

        session_start();
    }
}
