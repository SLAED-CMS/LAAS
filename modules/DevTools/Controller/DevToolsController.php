<?php
declare(strict_types=1);

namespace Laas\Modules\DevTools\Controller;

use Laas\Database\DatabaseManager;
use Laas\Database\Repositories\RbacRepository;
use Laas\DevTools\DevToolsContext;
use Laas\DevTools\JsErrorInbox;
use Laas\Http\Request;
use Laas\Http\Response;
use Laas\Support\Cache\FileCache;
use Laas\View\View;

final class DevToolsController
{
    private array $rateLimits = [];

    public function __construct(
        private View $view,
        private ?DatabaseManager $db = null
    ) {
    }

    public function ping(Request $request): Response
    {
        if (!$this->isAllowed($request)) {
            return Response::json(['error' => 'forbidden'], 403);
        }

        return Response::json(['status' => 'ok']);
    }

    public function panel(Request $request): Response
    {
        if (!$this->isAllowed($request)) {
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

    public function jsErrorsCollect(Request $request): Response
    {
        if (!$this->isAllowed($request)) {
            return Response::json(['error' => 'forbidden'], 403);
        }

        $userId = $this->getUserId($request);
        if ($userId === null) {
            return Response::json(['error' => 'unauthorized'], 401);
        }

        if (!$this->checkRateLimit($userId, 10, 60)) {
            return Response::json(['error' => 'rate limit exceeded'], 429);
        }

        $contentType = $request->header('Content-Type') ?? '';
        if (!str_contains(strtolower($contentType), 'application/json')) {
            return Response::json(['error' => 'content-type must be application/json'], 400);
        }

        $body = $request->getBody();
        if ($body === '') {
            return Response::json(['error' => 'empty body'], 400);
        }

        $data = json_decode($body, true);
        if (!is_array($data)) {
            return Response::json(['error' => 'invalid json'], 400);
        }

        if (!isset($data['type'], $data['message'])) {
            return Response::json(['error' => 'type and message are required'], 422);
        }

        $cache = new FileCache(dirname(__DIR__, 3) . '/storage/cache', 'devtools');
        $inbox = new JsErrorInbox($cache, $userId);
        $inbox->add($data);

        return new Response('', 204, ['Content-Type' => 'text/plain']);
    }

    public function jsErrorsList(Request $request): Response
    {
        if (!$this->isAllowed($request)) {
            return Response::html('', 403);
        }

        $userId = $this->getUserId($request);
        if ($userId === null) {
            return Response::html('', 401);
        }

        $cache = new FileCache(dirname(__DIR__, 3) . '/storage/cache', 'devtools');
        $inbox = new JsErrorInbox($cache, $userId);
        $errors = $inbox->list(200);

        // Format time_ago for each error
        $now = time();
        foreach ($errors as &$error) {
            $receivedAt = (int) ($error['received_at'] ?? 0);
            $diff = $now - $receivedAt;

            if ($diff < 60) {
                $error['time_ago'] = $diff . 's ago';
            } elseif ($diff < 3600) {
                $error['time_ago'] = floor($diff / 60) . 'm ago';
            } elseif ($diff < 86400) {
                $error['time_ago'] = floor($diff / 3600) . 'h ago';
            } else {
                $error['time_ago'] = floor($diff / 86400) . 'd ago';
            }

            $error['received_at_formatted'] = date('Y-m-d H:i:s', $receivedAt);
            $error['is_error'] = ($error['type'] ?? '') === 'error';
        }
        unset($error);

        return $this->view->render('pages/devtools/js_errors_list.html', [
            'errors' => $errors,
            'errors_count' => count($errors),
        ], 200, [], [
            'theme' => 'admin',
            'render_partial' => true,
        ]);
    }

    public function jsErrorsClear(Request $request): Response
    {
        if (!$this->isAllowed($request)) {
            return Response::json(['error' => 'forbidden'], 403);
        }

        $userId = $this->getUserId($request);
        if ($userId === null) {
            return Response::json(['error' => 'unauthorized'], 401);
        }

        $cache = new FileCache(dirname(__DIR__, 3) . '/storage/cache', 'devtools');
        $inbox = new JsErrorInbox($cache, $userId);
        $inbox->clear();

        // Return empty list HTML for HTMX swap
        return $this->view->render('pages/devtools/js_errors_list.html', [
            'errors' => [],
        ], 200, [], [
            'theme' => 'admin',
            'render_partial' => true,
        ]);
    }

    private function getUserId(Request $request): ?int
    {
        $session = $request->session();
        if (!$session->isStarted()) {
            return null;
        }

        $userId = $session->get('user_id');
        if (!is_int($userId) && !(is_string($userId) && ctype_digit($userId))) {
            return null;
        }

        return (int) $userId;
    }

    private function checkRateLimit(int $userId, int $maxEvents, int $windowSec): bool
    {
        $key = sprintf('js_errors_rate:%d', $userId);
        $now = time();

        if (!isset($this->rateLimits[$key])) {
            $this->rateLimits[$key] = ['count' => 0, 'window_start' => $now];
        }

        $limit = &$this->rateLimits[$key];

        if ($now - $limit['window_start'] >= $windowSec) {
            $limit['count'] = 0;
            $limit['window_start'] = $now;
        }

        if ($limit['count'] >= $maxEvents) {
            return false;
        }

        $limit['count']++;
        return true;
    }

    private function isAllowed(Request $request): bool
    {
        $appConfig = require dirname(__DIR__, 3) . '/config/app.php';
        $env = strtolower((string) ($appConfig['env'] ?? ''));
        if ($env === 'prod') {
            return false;
        }
        $debug = (bool) ($appConfig['debug'] ?? false);
        $devtoolsEnabled = (bool) (($appConfig['devtools']['enabled'] ?? false));
        if (!$debug || !$devtoolsEnabled) {
            return false;
        }

        if ($this->db === null || !$this->db->healthCheck()) {
            return false;
        }

        $session = $request->session();
        if (!$session->isStarted()) {
            return false;
        }

        $userId = $session->get('user_id');
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
