<?php
declare(strict_types=1);

namespace Laas\Http\Middleware;

use Laas\Http\ErrorCode;
use Laas\Http\ErrorResponse;
use Laas\Http\HeadlessMode;
use Laas\Http\Request;
use Laas\Http\Response;
use Laas\Http\Session\SessionManager;
use Laas\Session\SessionInterface;
use Laas\Support\Cache\CacheFactory;
use Laas\Support\Cache\CacheInterface;
use Laas\Support\Cache\CacheKey;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

final class SessionMiddleware implements MiddlewareInterface
{
    private int $idleTtlSeconds;
    private int $absoluteTtlSeconds;
    private LoggerInterface $logger;
    private ?SessionInterface $session;
    private CacheInterface $cache;
    private string $rootPath;

    public function __construct(
        private SessionManager $sessionManager,
        ?array $config = null,
        ?LoggerInterface $logger = null,
        ?SessionInterface $session = null,
        ?string $rootPath = null
    ) {
        $sessionConfig = $config ?? [];
        $this->idleTtlSeconds = $this->resolveIdleTtlSeconds($sessionConfig);
        $this->absoluteTtlSeconds = $this->resolveAbsoluteTtlSeconds($sessionConfig);
        $this->logger = $logger ?? new NullLogger();
        $this->session = $session;
        $this->rootPath = $rootPath ?? dirname(__DIR__, 3);
        $this->cache = CacheFactory::create($this->rootPath);
    }

    public function process(Request $request, callable $next): Response
    {
        $session = $this->sessionManager->start($request, $this->session);
        $request->setSession($session);

        if ($session->isStarted()) {
            $now = time();
            $startedAt = (int) $session->get('_session_started_at', 0);
            if ($startedAt <= 0) {
                $startedAt = $now;
                $session->set('_session_started_at', $startedAt);
            }

            if ($this->absoluteTtlSeconds > 0 && ($now - $startedAt) > $this->absoluteTtlSeconds) {
                $this->logger->info('Session expired due to absolute timeout', [
                    'started_at' => date('Y-m-d H:i:s', $startedAt),
                    'timeout_seconds' => $this->absoluteTtlSeconds,
                ]);
                $this->invalidateSession($session);
                return $this->expiredResponse($request);
            }

            $lastActivity = (int) $session->get('_last_activity', 0);
            if ($this->idleTtlSeconds > 0 && $lastActivity > 0 && ($now - $lastActivity) > $this->idleTtlSeconds) {
                $this->logger->info('Session expired due to inactivity', [
                    'last_activity' => date('Y-m-d H:i:s', $lastActivity),
                    'timeout_seconds' => $this->idleTtlSeconds,
                ]);
                $this->invalidateSession($session);
                return $this->expiredResponse($request);
            }

            $this->rotateOnRoleChange($session);
            $session->set('_last_activity', $now);
        }

        return $next($request);
    }

    private function resolveIdleTtlSeconds(array $config): int
    {
        $idleMinutes = (int) ($config['idle_ttl'] ?? 0);
        if ($idleMinutes > 0) {
            return $idleMinutes * 60;
        }

        $legacy = (int) ($config['timeout'] ?? 0);
        return $legacy > 0 ? $legacy : 0;
    }

    private function resolveAbsoluteTtlSeconds(array $config): int
    {
        $absoluteMinutes = (int) ($config['absolute_ttl'] ?? 0);
        return $absoluteMinutes > 0 ? ($absoluteMinutes * 60) : 0;
    }

    private function invalidateSession(SessionInterface $session): void
    {
        $session->clear();
        $session->regenerateId(true);
    }

    private function expiredResponse(Request $request): Response
    {
        if ($request->wantsJson() || HeadlessMode::shouldDefaultJson($request)) {
            return ErrorResponse::respond($request, ErrorCode::AUTH_REQUIRED, [], 401, [], 'session.middleware');
        }

        return new Response('', 302, [
            'Location' => '/login',
        ]);
    }

    private function rotateOnRoleChange(SessionInterface $session): void
    {
        $userId = $this->readUserId($session);
        if ($userId === null) {
            return;
        }

        $marker = $this->cache->get(CacheKey::sessionRbacVersion($userId));
        $markerValue = $this->normalizeInt($marker);
        if ($markerValue <= 0) {
            return;
        }

        $known = $this->normalizeInt($session->get('_rbac_version', 0));
        if ($markerValue > $known) {
            $session->regenerateId(true);
            $session->set('_rbac_version', $markerValue);
        }
    }

    private function readUserId(SessionInterface $session): ?int
    {
        $raw = $session->get('user_id', null);
        if (is_int($raw) && $raw > 0) {
            return $raw;
        }
        if (is_string($raw) && ctype_digit($raw)) {
            $value = (int) $raw;
            return $value > 0 ? $value : null;
        }
        return null;
    }

    private function normalizeInt(mixed $value): int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && ctype_digit($value)) {
            return (int) $value;
        }
        return 0;
    }
}
