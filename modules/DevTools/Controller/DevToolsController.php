<?php
declare(strict_types=1);

namespace Laas\Modules\DevTools\Controller;

use Laas\Database\DatabaseManager;
use Laas\Database\Repositories\RbacRepository;
use Laas\DevTools\DevToolsContext;
use Laas\Http\Request;
use Laas\Http\Response;
use Laas\View\View;

final class DevToolsController
{
    public function __construct(
        private View $view,
        private ?DatabaseManager $db = null
    ) {
    }

    public function ping(Request $request): Response
    {
        if (!$this->isAllowed()) {
            return Response::json(['error' => 'forbidden'], 403);
        }

        return Response::json(['status' => 'ok']);
    }

    public function panel(Request $request): Response
    {
        if (!$this->isAllowed()) {
            return Response::json(['error' => 'forbidden'], 403);
        }

        $context = new DevToolsContext([
            'enabled' => true,
            'debug' => true,
            'env' => 'dev',
            'collect_db' => false,
            'collect_request' => false,
            'collect_logs' => false,
        ]);
        $context->setRequest([
            'method' => $request->getMethod(),
            'path' => $request->getPath(),
            'get' => [],
            'post' => [],
            'cookies' => [],
            'headers' => [],
        ]);
        $context->finalize();

        $theme = str_starts_with($request->getPath(), '/admin') ? 'admin' : 'default';

        return $this->view->render('partials/devtools_toolbar.html', [
            'devtools' => $context->toArray(),
        ], 200, [], [
            'theme' => $theme,
            'render_partial' => true,
        ]);
    }

    private function isAllowed(): bool
    {
        $appConfig = require dirname(__DIR__, 3) . '/config/app.php';
        $debug = (bool) ($appConfig['debug'] ?? false);
        $devtoolsEnabled = (bool) (($appConfig['devtools']['enabled'] ?? false));
        if (!$debug || !$devtoolsEnabled) {
            return false;
        }

        if ($this->db === null || !$this->db->healthCheck()) {
            return false;
        }

        if (session_status() !== PHP_SESSION_ACTIVE) {
            return false;
        }

        $userId = $_SESSION['user_id'] ?? null;
        if (!is_int($userId) && !(is_string($userId) && ctype_digit($userId))) {
            return false;
        }

        $userId = (int) $userId;

        try {
            $rbac = new RbacRepository($this->db->pdo());
            return $rbac->userHasPermission($userId, 'debug.view');
        } catch (\Throwable) {
            return false;
        }
    }
}
