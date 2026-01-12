<?php
declare(strict_types=1);

namespace Laas\Http\Middleware;

use Laas\Http\Request;
use Laas\Http\Response;
use Laas\Http\Session\SessionManager;
use Laas\Session\SessionInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

final class SessionMiddleware implements MiddlewareInterface
{
    private int $timeout;
    private LoggerInterface $logger;
    private ?SessionInterface $session;

    public function __construct(
        private SessionManager $sessionManager,
        ?array $config = null,
        ?LoggerInterface $logger = null,
        ?SessionInterface $session = null
    ) {
        $this->timeout = (int) ($config['timeout'] ?? 7200);
        $this->logger = $logger ?? new NullLogger();
        $this->session = $session;
    }

    public function process(Request $request, callable $next): Response
    {
        $session = $this->sessionManager->start($request, $this->session);
        $request->setSession($session);

        // Session timeout check
        if ($this->timeout > 0 && $session->isStarted()) {
            $lastActivity = (int) $session->get('_last_activity', 0);

            if ($lastActivity > 0 && (time() - $lastActivity) > $this->timeout) {
                // Session expired due to inactivity
                $this->logger->info('Session expired due to inactivity', [
                    'last_activity' => date('Y-m-d H:i:s', $lastActivity),
                    'timeout_seconds' => $this->timeout,
                ]);

                $session->clear();
                $session->regenerateId(true);
            }

            // Update last activity timestamp
            $session->set('_last_activity', time());
        }

        return $next($request);
    }
}
