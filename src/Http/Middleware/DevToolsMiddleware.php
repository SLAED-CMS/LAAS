<?php

declare(strict_types=1);

namespace Laas\Http\Middleware;

use Laas\Auth\AuthInterface;
use Laas\Auth\AuthorizationService;
use Laas\Database\DatabaseManager;
use Laas\Database\Repositories\RbacRepository;
use Laas\DevTools\CollectorInterface;
use Laas\DevTools\DevToolsContext;
use Laas\Http\Request;
use Laas\Http\Response;
use Laas\Support\Cache\CacheFactory;
use Laas\Support\RequestScope;
use Laas\View\View;

final class DevToolsMiddleware implements MiddlewareInterface
{
    /** @param CollectorInterface[] $collectors */
    public function __construct(
        private DevToolsContext $context,
        private array $config,
        private AuthInterface $auth,
        private AuthorizationService $authorization,
        private View $view,
        private ?DatabaseManager $db = null,
        private array $collectors = []
    ) {
    }

    public function process(Request $request, callable $next): Response
    {
        $response = $next($request);

        if (!$this->isEnabled()) {
            RequestScope::set('response', $response);
            return $response;
        }

        $this->collect($request, $response);

        if (!$this->shouldShow($request, $response)) {
            RequestScope::set('response', $response);
            return $response;
        }

        $toolbar = $this->renderToolbar($request);
        if ($toolbar === '') {
            RequestScope::set('response', $response);
            return $response;
        }

        $body = $response->getBody();
        $updated = $this->injectToolbar($body, $toolbar);

        $final = $response->withBody($updated);
        RequestScope::set('response', $final);
        return $final;
    }

    private function isEnabled(): bool
    {
        return !empty($this->config['enabled']);
    }

    private function collect(Request $request, Response $response): void
    {
        $user = $this->auth->user();
        $roles = [];
        if (is_array($user) && $this->db !== null) {
            try {
                if ($this->db->healthCheck()) {
                    $repo = new RbacRepository($this->db->pdo());
                    $roles = $repo->listUserRoles((int) ($user['id'] ?? 0));
                }
            } catch (\Throwable) {
                $roles = [];
            }
        }

        $rawSqlAllowed = false;
        if (is_array($user) && $this->authorization->can($user, 'admin.access')) {
            $rawSqlAllowed = (bool) $this->context->getFlag('debug', false)
                && (bool) ($this->config['show_secrets'] ?? false);
        }
        $this->context->setRawSqlAllowed($rawSqlAllowed);

        $this->context->setUser([
            'id' => $user['id'] ?? null,
            'username' => $user['username'] ?? null,
            'roles' => $roles,
        ]);

        // Load JS Errors
        if (is_array($user) && isset($user['id'])) {
            try {
                $rootPath = dirname(__DIR__, 3);
                $cacheConfig = CacheFactory::config($rootPath);
                $track = (bool) ($cacheConfig['devtools_tracking'] ?? true);
                $cache = new \Laas\Support\Cache\FileCache($rootPath . '/storage/cache', 'devtools', 300, $track);
                $inbox = new \Laas\DevTools\JsErrorInbox($cache, (int) $user['id']);
                $errors = $inbox->list(200);

                // Format time_ago for each error
                $now = time();
                foreach ($errors as &$error) {
                    $receivedAt = (int) ($error['received_at'] ?? 0);
                    $diff = $now - $receivedAt;

                    if ($diff < 60) {
                        $error['time_ago'] = 'just now';
                    } elseif ($diff < 3600) {
                        $mins = (int) floor($diff / 60);
                        $error['time_ago'] = $mins . 'm ago';
                    } else {
                        $hours = (int) floor($diff / 3600);
                        $error['time_ago'] = $hours . 'h ago';
                    }

                    $error['is_error'] = ($error['type'] ?? '') === 'error';
                }
                unset($error);

                $this->context->setJsErrors($errors);
            } catch (\Throwable) {
                $this->context->setJsErrors([]);
            }
        }

        foreach ($this->collectors as $collector) {
            $collector->collect($request, $response, $this->context);
        }
    }

    private function shouldShow(Request $request, Response $response): bool
    {
        if ($response->getStatus() >= 500) {
            return false;
        }

        if (!$this->auth->check()) {
            return false;
        }

        if (!$this->authorization->can($this->auth->user(), 'debug.view')) {
            return false;
        }

        if ($request->wantsJson()) {
            return false;
        }

        $contentType = $response->getHeader('Content-Type') ?? '';
        if ($contentType !== '' && stripos($contentType, 'text/html') === false) {
            return false;
        }

        return true;
    }

    private function renderToolbar(Request $request): string
    {
        $theme = str_starts_with($request->getPath(), '/admin') ? 'admin' : null;

        return $this->view->renderPartial('partials/devtools_toolbar.html', [
            'devtools' => $this->context->toArray(),
        ], $theme !== null ? ['theme' => $theme] : []);
    }

    private function injectToolbar(string $body, string $toolbar): string
    {
        $pos = strripos($body, '</body>');
        if ($pos === false) {
            return $body . $toolbar;
        }

        return substr($body, 0, $pos) . $toolbar . substr($body, $pos);
    }
}
