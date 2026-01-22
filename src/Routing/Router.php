<?php

declare(strict_types=1);

namespace Laas\Routing;

use function FastRoute\cachedDispatcher;

use FastRoute\Dispatcher;
use FastRoute\RouteCollector;
use Laas\Http\ErrorCode;
use Laas\Http\ErrorResponse;
use Laas\Http\Request;
use Laas\Http\Response;
use Laas\Support\RequestScope;

final class Router
{
    private array $routes = [];
    private string $cacheFile;
    private string $fingerprintFile;
    /** @var array<string, array<string, mixed>> */
    private array $contexts = [];

    public function __construct(?string $cacheDir = null, bool $debug = false)
    {
        $cacheDir = $cacheDir ?? (sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'laas-route-cache');
        $cacheDir = rtrim($cacheDir, '/\\');
        $this->cacheFile = $cacheDir . DIRECTORY_SEPARATOR . 'routes.php';
        $this->fingerprintFile = $cacheDir . DIRECTORY_SEPARATOR . 'routes.sha1';
        if ($debug) {
        }
        if (!is_dir($cacheDir)) {
            mkdir($cacheDir, 0775, true);
        }
    }

    public function addRoute(string $method, string $path, mixed $handler): void
    {
        $this->routes[] = [$method, $path, $handler];
    }

    /**
     * @param array<string, mixed> $context
     */
    public function registerContext(string $key, array $context): void
    {
        $this->contexts[$key] = $context;
    }

    public function dispatch(Request $request): Response
    {
        $dispatcher = $this->getDispatcher();

        $routeInfo = $dispatcher->dispatch(strtoupper($request->getMethod()), $request->getPath());

        switch ($routeInfo[0]) {
            case Dispatcher::NOT_FOUND:
                $headers = [];
                $devtoolsPaths = RequestScope::get('devtools.paths');
                if (is_array($devtoolsPaths) && in_array($request->getPath(), $devtoolsPaths, true)) {
                    $headers['Cache-Control'] = 'no-store';
                }
                return ErrorResponse::respondForRequest($request, ErrorCode::NOT_FOUND, [], 404, [], 'router', $headers);
            case Dispatcher::METHOD_NOT_ALLOWED:
                $allowed = $routeInfo[1] ?? [];
                $allowHeader = is_array($allowed) ? implode(', ', $allowed) : '';
                $headers = $allowHeader !== '' ? ['Allow' => $allowHeader] : [];
                return ErrorResponse::respondForRequest($request, ErrorCode::METHOD_NOT_ALLOWED, [], 405, [], 'router', $headers);
            case Dispatcher::FOUND:
                $handler = $routeInfo[1];
                $vars = $routeInfo[2];
                $routePattern = $this->findRoutePattern($request, $handler);
                if ($routePattern !== null) {
                    $request->setAttribute('route.pattern', $routePattern);
                }
                $request->setAttribute('route.handler', $handler);
                $request->setAttribute('route.vars', $vars);

                $response = $this->invokeHandler($handler, $request, $vars);
                if ($response instanceof Response) {
                    return $response;
                }

                return new Response('', 204);
        }

        return new Response('Unhandled Router State', 500, [
            'Content-Type' => 'text/plain; charset=utf-8',
        ]);
    }

    private function findRoutePattern(Request $request, mixed $handler): ?string
    {
        $method = strtoupper($request->getMethod());
        foreach ($this->routes as [$routeMethod, $path, $routeHandler]) {
            if (strtoupper((string) $routeMethod) !== $method) {
                continue;
            }
            if ($routeHandler === $handler) {
                return is_string($path) ? $path : null;
            }
        }

        return null;
    }

    private function computeFingerprint(): string
    {
        $tuples = [];
        foreach ($this->routes as [$method, $path, $handler]) {
            $tuples[] = [
                (string) $method,
                (string) $path,
                $this->handlerFingerprint($handler),
            ];
        }

        $encoded = json_encode($tuples, JSON_UNESCAPED_SLASHES);
        if ($encoded === false) {
            $encoded = '';
        }

        return sha1($encoded);
    }

    private function getDispatcher(): Dispatcher
    {
        $closureCount = $this->countClosureHandlers();
        $cacheDisabled = $closureCount > 0;
        if ($cacheDisabled) {
            if (is_file($this->cacheFile)) {
                @unlink($this->cacheFile);
            }
            if (is_file($this->fingerprintFile)) {
                @unlink($this->fingerprintFile);
            }

            $dispatcher = cachedDispatcher(function (RouteCollector $r): void {
                foreach ($this->routes as [$method, $path, $handler]) {
                    $r->addRoute($method, $path, $handler);
                }
            }, [
                'cacheFile' => $this->cacheFile,
                'cacheDisabled' => true,
            ]);

            $this->logCache('DISABLED (closures: ' . $closureCount . ')');
            return $dispatcher;
        }

        $fingerprint = $this->computeFingerprint();
        $cacheValid = $this->isCacheValid($fingerprint);
        if (!$cacheValid && is_file($this->cacheFile)) {
            @unlink($this->cacheFile);
        }

        $dispatcher = cachedDispatcher(function (RouteCollector $r): void {
            foreach ($this->routes as [$method, $path, $handler]) {
                $r->addRoute($method, $path, $handler);
            }
        }, [
            'cacheFile' => $this->cacheFile,
        ]);

        if (!$cacheValid) {
            $this->writeFingerprint($fingerprint);
            $this->logCache('REBUILT');
        } else {
            $this->logCache('HIT');
        }

        return $dispatcher;
    }

    /**
     * @return array{fingerprint: string, cache_file: string, hit: bool, status: string}
     */
    public function warmCache(bool $force = false): array
    {
        if ($force) {
            if (is_file($this->cacheFile)) {
                @unlink($this->cacheFile);
            }
            if (is_file($this->fingerprintFile)) {
                @unlink($this->fingerprintFile);
            }
        }

        $closureCount = $this->countClosureHandlers();
        if ($closureCount > 0) {
            if (is_file($this->cacheFile)) {
                @unlink($this->cacheFile);
            }
            if (is_file($this->fingerprintFile)) {
                @unlink($this->fingerprintFile);
            }

            cachedDispatcher(function (RouteCollector $r): void {
                foreach ($this->routes as [$method, $path, $handler]) {
                    $r->addRoute($method, $path, $handler);
                }
            }, [
                'cacheFile' => $this->cacheFile,
                'cacheDisabled' => true,
            ]);

            return [
                'fingerprint' => '',
                'cache_file' => $this->cacheFile,
                'hit' => false,
                'status' => 'DISABLED',
            ];
        }

        $fingerprint = $this->computeFingerprint();
        $cacheValid = !$force && $this->isCacheValid($fingerprint);
        if (!$cacheValid && is_file($this->cacheFile)) {
            @unlink($this->cacheFile);
        }

        cachedDispatcher(function (RouteCollector $r): void {
            foreach ($this->routes as [$method, $path, $handler]) {
                $r->addRoute($method, $path, $handler);
            }
        }, [
            'cacheFile' => $this->cacheFile,
        ]);

        if (!$cacheValid) {
            $this->writeFingerprint($fingerprint);
        }

        return [
            'fingerprint' => $fingerprint,
            'cache_file' => $this->cacheFile,
            'hit' => $cacheValid,
            'status' => $cacheValid ? 'HIT' : 'REBUILT',
        ];
    }

    private function countClosureHandlers(): int
    {
        $count = 0;
        foreach ($this->routes as $route) {
            if (isset($route[2]) && $route[2] instanceof \Closure) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * @param array<string, mixed> $vars
     */
    private function invokeHandler(mixed $handler, Request $request, array $vars): mixed
    {
        if (is_array($handler) && isset($handler['type'])) {
            return $this->dispatchSpec($handler, $request, $vars);
        }

        if (is_callable($handler)) {
            return $handler($request, $vars);
        }

        return new Response('Invalid route handler', 500, [
            'Content-Type' => 'text/plain; charset=utf-8',
        ]);
    }

    /**
     * @param array<string, mixed> $spec
     * @param array<string, mixed> $vars
     */
    private function dispatchSpec(array $spec, Request $request, array $vars): Response
    {
        $type = (string) ($spec['type'] ?? '');
        if ($type === RouteHandlerSpec::TYPE_CONTROLLER) {
            return $this->dispatchControllerSpec($spec, $request, $vars);
        }
        if ($type === RouteHandlerSpec::TYPE_MODULE) {
            return $this->dispatchModuleSpec($spec, $request, $vars);
        }

        return new Response('Invalid route handler spec', 500, [
            'Content-Type' => 'text/plain; charset=utf-8',
        ]);
    }

    /**
     * @param array<string, mixed> $spec
     * @param array<string, mixed> $vars
     */
    private function dispatchControllerSpec(array $spec, Request $request, array $vars): Response
    {
        $class = (string) ($spec['class'] ?? '');
        $action = (string) ($spec['action'] ?? '');
        $contextKey = (string) ($spec['context'] ?? '');
        if ($class === '' || $action === '' || $contextKey === '') {
            return new Response('Invalid controller handler spec', 500, [
                'Content-Type' => 'text/plain; charset=utf-8',
            ]);
        }

        $context = $this->contexts[$contextKey] ?? [];
        $tokens = is_array($spec['ctor'] ?? null) ? $spec['ctor'] : [];
        $args = RouteHandlerTokens::resolve($tokens, $context);

        $controller = $args === [] ? new $class() : new $class(...$args);
        $passVars = (bool) ($spec['pass_vars'] ?? false);
        return $passVars ? $controller->{$action}($request, $vars) : $controller->{$action}($request);
    }

    /**
     * @param array<string, mixed> $spec
     * @param array<string, mixed> $vars
     */
    private function dispatchModuleSpec(array $spec, Request $request, array $vars): Response
    {
        $contextKey = (string) ($spec['context'] ?? '');
        $class = (string) ($spec['class'] ?? '');
        $action = (string) ($spec['action'] ?? '');
        if ($contextKey === '' || $class === '' || $action === '') {
            return new Response('Invalid module handler spec', 500, [
                'Content-Type' => 'text/plain; charset=utf-8',
            ]);
        }

        $context = $this->contexts[$contextKey] ?? [];
        $module = $context['module'] ?? null;
        if (is_object($module) && method_exists($module, 'dispatchRoute')) {
            return $module->dispatchRoute($class, $action, $request, $vars);
        }

        return new Response('Module handler not available', 500, [
            'Content-Type' => 'text/plain; charset=utf-8',
        ]);
    }
    private function handlerFingerprint(mixed $handler): string
    {
        if (is_array($handler) && isset($handler['type'])) {
            $encoded = json_encode($handler, JSON_UNESCAPED_SLASHES);
            if ($encoded === false) {
                $encoded = '';
            }
            return 'spec:' . sha1($encoded);
        }
        if (is_string($handler)) {
            return $handler;
        }
        if (is_array($handler)) {
            $target = is_object($handler[0]) ? get_class($handler[0]) : (string) $handler[0];
            return $target . '::' . (string) ($handler[1] ?? '');
        }
        if ($handler instanceof \Closure) {
            $ref = new \ReflectionFunction($handler);
            $staticVars = $this->normalizeValue($ref->getStaticVariables());
            $meta = [
                'file' => $ref->getFileName(),
                'start' => $ref->getStartLine(),
                'end' => $ref->getEndLine(),
                'static' => $staticVars,
            ];
            $encoded = json_encode($meta, JSON_UNESCAPED_SLASHES);
            if ($encoded === false) {
                $encoded = '';
            }
            return 'closure:' . $encoded;
        }
        if (is_object($handler) && method_exists($handler, '__invoke')) {
            return get_class($handler) . '::__invoke';
        }

        return 'callable';
    }

    private function normalizeValue(mixed $value): mixed
    {
        if (is_array($value)) {
            $normalized = [];
            foreach ($value as $key => $item) {
                $normalized[$key] = $this->normalizeValue($item);
            }
            return $normalized;
        }
        if (is_object($value)) {
            return 'object:' . get_class($value);
        }
        if (is_resource($value)) {
            return 'resource';
        }

        return $value;
    }

    private function isCacheValid(string $fingerprint): bool
    {
        if (!is_file($this->cacheFile) || !is_file($this->fingerprintFile)) {
            return false;
        }

        $stored = trim((string) file_get_contents($this->fingerprintFile));
        if ($stored === '') {
            return false;
        }

        return hash_equals($stored, $fingerprint);
    }

    private function writeFingerprint(string $fingerprint): void
    {
        file_put_contents($this->fingerprintFile, $fingerprint);
    }

    private function logCache(string $status): void
    {
    }
}
