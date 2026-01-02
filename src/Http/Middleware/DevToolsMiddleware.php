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
            return $response;
        }

        $this->collect($request, $response);

        if (!$this->shouldShow($request, $response)) {
            return $response;
        }

        $toolbar = $this->renderToolbar($request);
        if ($toolbar === '') {
            return $response;
        }

        $body = $response->getBody();
        $updated = $this->injectToolbar($body, $toolbar);

        return $response->withBody($updated);
    }

    private function isEnabled(): bool
    {
        return !empty($this->config['enabled']);
    }

    private function collect(Request $request, Response $response): void
    {
        foreach ($this->collectors as $collector) {
            $collector->collect($request, $response, $this->context);
        }

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

        $this->context->setUser([
            'id' => $user['id'] ?? null,
            'username' => $user['username'] ?? null,
            'roles' => $roles,
        ]);
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
