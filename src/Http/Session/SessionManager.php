<?php
declare(strict_types=1);

namespace Laas\Http\Session;

use Laas\Http\Request;
use Laas\Session\NativeSession;
use Laas\Session\SessionFactory;
use Laas\Session\SessionInterface;
use RuntimeException;

final class SessionManager
{
    private SessionFactory $factory;

    public function __construct(
        private string $rootPath,
        private array $config,
        ?SessionFactory $factory = null
    ) {
        $this->factory = $factory ?? new SessionFactory($config['session'] ?? [], null, $rootPath);
    }

    public function start(?Request $request = null, ?SessionInterface $session = null): SessionInterface
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            $session ??= new NativeSession();
            $session->start();
            return $session;
        }

        $savePath = $this->rootPath . '/storage/sessions';
        if (!is_dir($savePath) && !mkdir($savePath, 0775, true) && !is_dir($savePath)) {
            throw new RuntimeException('Unable to create session directory: ' . $savePath);
        }

        ini_set('session.save_path', $savePath);
        ini_set('session.use_strict_mode', '1');

        $this->factory->applyCookiePolicy($request?->isHttps() ?? false);

        $session ??= new NativeSession();
        $session->start();
        return $session;
    }
}
