<?php
declare(strict_types=1);

namespace Laas\Modules\Admin\Controller;

use Laas\Core\Container\Container;
use Laas\Domain\Rbac\RbacServiceInterface;
use Laas\Http\ErrorResponse;
use Laas\Http\Request;
use Laas\Http\Response;
use Laas\Support\SafeHttpClient;
use Laas\Support\UrlPolicy;
use Laas\View\View;
use Throwable;

final class HeadlessPlaygroundController
{
    private ?RbacServiceInterface $rbacService = null;

    public function __construct(
        private View $view,
        private ?SafeHttpClient $httpClient = null,
        private ?Container $container = null
    ) {
    }

    public function index(Request $request): Response
    {
        if (!$this->hasAccess($request)) {
            return $this->forbidden($request, 'admin.headless.index');
        }

        $defaultUrl = '/api/v2/pages?fields=id,title,slug&limit=5';

        return $this->view->render('pages/headless_playground.html', [
            'default_url' => $defaultUrl,
            'input_url' => $defaultUrl,
            'fetch_result' => null,
        ], 200, [], [
            'theme' => 'admin',
        ]);
    }

    public function fetch(Request $request): Response
    {
        if (!$this->hasAccess($request)) {
            return $this->forbidden($request, 'admin.headless.fetch');
        }

        $inputUrl = trim((string) ($request->query('url') ?? ''));
        $normalized = $this->normalizePath($inputUrl);
        if ($normalized['error'] !== null) {
            return $this->renderFetchResult($request, $inputUrl, [
                'error' => $normalized['error'],
                'status' => null,
            ], 400);
        }

        $host = $this->requestHost($request);
        if ($host === '') {
            return $this->renderFetchResult($request, $inputUrl, [
                'error' => 'Missing host.',
                'status' => null,
            ], 400);
        }

        $scheme = $request->isHttps() ? 'https' : 'http';
        $url = $scheme . '://' . $host . $normalized['path'];

        $client = $this->httpClient ?? $this->buildClient($host);
        try {
            $response = $client->request('GET', $url, [
                'Accept' => 'application/json',
                'X-Requested-With' => 'HeadlessPlayground',
            ], null, [
                'timeout' => 6,
                'connect_timeout' => 2,
                'max_bytes' => 200_000,
            ]);
        } catch (Throwable) {
            return $this->renderFetchResult($request, $inputUrl, [
                'error' => 'Request failed.',
                'status' => null,
            ], 502);
        }

        $headers = $response['headers'] ?? [];
        $body = (string) ($response['body'] ?? '');

        $result = [
            'status' => (int) ($response['status'] ?? 0),
            'headers' => [
                'etag' => $headers['etag'] ?? '',
                'cache_control' => $headers['cache-control'] ?? '',
                'content_type' => $headers['content-type'] ?? '',
                'content_length' => $headers['content-length'] ?? '',
            ],
            'body' => $body,
        ];

        return $this->renderFetchResult($request, $inputUrl, $result, 200);
    }

    /**
     * @return array{path: string, error: string|null}
     */
    private function normalizePath(string $value): array
    {
        $value = trim($value);
        if ($value === '') {
            return ['path' => '', 'error' => 'URL is required.'];
        }
        if (preg_match('/[\r\n]/', $value) === 1) {
            return ['path' => '', 'error' => 'Invalid URL.'];
        }
        if (str_starts_with($value, '//')) {
            return ['path' => '', 'error' => 'External URLs are not allowed.'];
        }
        if (preg_match('/^[a-zA-Z][a-zA-Z0-9+.-]*:/', $value) === 1) {
            return ['path' => '', 'error' => 'External URLs are not allowed.'];
        }
        if (!str_starts_with($value, '/')) {
            return ['path' => '', 'error' => 'Path must start with /.'];
        }
        if (!str_starts_with($value, '/api/v2')) {
            return ['path' => '', 'error' => 'Only /api/v2 endpoints are allowed.'];
        }

        return ['path' => $value, 'error' => null];
    }

    private function requestHost(Request $request): string
    {
        $host = trim((string) ($request->getHeader('host') ?? ''));
        if ($host === '') {
            return '';
        }

        $parsed = parse_url('http://' . $host);
        if (!is_array($parsed) || ($parsed['host'] ?? '') === '') {
            return $host;
        }

        $hostname = (string) ($parsed['host'] ?? '');
        $port = isset($parsed['port']) ? ':' . (int) $parsed['port'] : '';
        return $hostname . $port;
    }

    private function buildClient(string $host): SafeHttpClient
    {
        $hostname = $host;
        if (str_contains($hostname, ':')) {
            $parsed = parse_url('http://' . $hostname);
            if (is_array($parsed) && isset($parsed['host'])) {
                $hostname = (string) $parsed['host'];
            }
        }

        $policy = new UrlPolicy(
            ['http', 'https'],
            [$hostname],
            true,
            true,
            false,
            [],
            static function (string $host): array {
                if (filter_var($host, FILTER_VALIDATE_IP)) {
                    return [$host];
                }
                $ips = gethostbynamel($host);
                return is_array($ips) ? $ips : [];
            }
        );

        return new SafeHttpClient($policy, 6, 2, 0, 200_000);
    }

    private function renderFetchResult(Request $request, string $inputUrl, array $result, int $status): Response
    {
        if ($request->wantsJson()) {
            return Response::json([
                'input' => $inputUrl,
                'result' => $result,
            ], $status);
        }

        if ($request->isHtmx()) {
            return $this->view->render('partials/headless_playground_result.html', [
                'result' => $result,
            ], $status, [], [
                'theme' => 'admin',
                'render_partial' => true,
            ]);
        }

        return $this->view->render('pages/headless_playground.html', [
            'default_url' => '/api/v2/pages?fields=id,title,slug&limit=5',
            'input_url' => $inputUrl,
            'fetch_result' => $result,
            'result' => $result,
        ], $status, [], [
            'theme' => 'admin',
        ]);
    }

    private function hasAccess(Request $request): bool
    {
        $userId = $this->currentUserId($request);
        if ($userId === null) {
            return false;
        }

        $rbac = $this->rbacService();
        if ($rbac === null) {
            return false;
        }

        return $rbac->userHasPermission($userId, 'admin.access');
    }

    private function currentUserId(Request $request): ?int
    {
        $session = $request->session();
        if (!$session->isStarted()) {
            return null;
        }

        $raw = $session->get('user_id');
        if (is_int($raw)) {
            return $raw;
        }
        if (is_string($raw) && ctype_digit($raw)) {
            return (int) $raw;
        }

        return null;
    }

    private function rbacService(): ?RbacServiceInterface
    {
        if ($this->rbacService !== null) {
            return $this->rbacService;
        }

        if ($this->container !== null) {
            try {
                $service = $this->container->get(RbacServiceInterface::class);
                if ($service instanceof RbacServiceInterface) {
                    $this->rbacService = $service;
                    return $this->rbacService;
                }
            } catch (Throwable) {
                return null;
            }
        }

        return null;
    }

    private function forbidden(Request $request, string $route): Response
    {
        return ErrorResponse::respondForRequest($request, 'forbidden', [], 403, [], $route);
    }
}
