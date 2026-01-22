<?php

declare(strict_types=1);

namespace Laas\Http;

use Laas\I18n\LocaleResolver;
use Laas\I18n\Translator;
use Laas\Support\RequestScope;
use Laas\View\View;

final class ErrorResponse
{
    private static ?Translator $translator = null;
    private static ?LocaleResolver $localeResolver = null;
    private static ?array $appConfig = null;
    private const SECURITY_ERRORS = [
        ErrorCode::AUTH_REQUIRED,
        ErrorCode::AUTH_INVALID,
        ErrorCode::RBAC_DENIED,
        ErrorCode::CSRF_INVALID,
        ErrorCode::RATE_LIMITED,
        ErrorCode::API_TOKEN_INVALID,
    ];

    /**
     * @return array{payload: array<string, mixed>, status: int, toast: array<string, mixed>}
     */
    public static function buildPayload(
        ?Request $request,
        string $codeOrAlias,
        array $details = [],
        ?int $status = null,
        array $meta = [],
        ?string $source = null
    ): array {
        $request ??= RequestScope::getRequest();
        $resolved = ErrorCatalog::resolve($codeOrAlias);
        $code = $resolved['code'];
        $status ??= $resolved['status'];

        $message = self::translate($resolved['message_key'], $request);

        $details = self::normalizeDetails($code, $details);
        if (self::isSecurityError($code) && !self::isDebug()) {
            $details = [];
        }
        $details = self::attachSource($details, $source);

        if ($source !== null && $source !== '') {
            RequestScope::set('error.source', $source);
        }
        RequestScope::set('error.code', $code);

        $meta = ResponseMeta::enrich($meta);
        $meta['ok'] = false;
        $meta['error'] = [
            'key' => $resolved['message_key'],
            'message' => $message,
        ];
        $meta['problem'] = self::buildProblemDetails(
            $request,
            $resolved['message_key'],
            $message,
            $status,
            $details,
            $source,
            $meta
        );

        $payload = [
            'data' => null,
            'error' => [
                'code' => $code,
                'message' => $message,
            ],
            'meta' => $meta,
        ];

        if ($details !== []) {
            $payload['error']['details'] = $details;
        }

        $toast = self::registerErrorToast($payload);
        if ($toast !== []) {
            $payload['meta'] = ResponseMeta::enrich($payload['meta']);
        }

        return [
            'payload' => $payload,
            'status' => $status,
            'toast' => $toast,
        ];
    }

    public static function respond(
        ?Request $request,
        string $codeOrAlias,
        array $details = [],
        ?int $status = null,
        array $meta = [],
        ?string $source = null,
        array $headers = []
    ): Response {
        $built = self::buildPayload($request, $codeOrAlias, $details, $status, $meta, $source);
        $response = Response::json($built['payload'], $built['status']);
        foreach ($headers as $name => $value) {
            $response = $response->withHeader($name, $value);
        }

        return self::attachHtmxTrigger($request, $response, $built['toast']);
    }

    public static function respondForRequest(
        Request $request,
        string $codeOrAlias,
        array $details = [],
        ?int $status = null,
        array $meta = [],
        ?string $source = null,
        array $headers = []
    ): Response {
        $built = self::buildPayload($request, $codeOrAlias, $details, $status, $meta, $source);
        $resolvedStatus = $built['status'];

        if ($request->wantsJson()) {
            $response = Response::json($built['payload'], $resolvedStatus);
        } else {
            $response = self::renderHtmlError($request, $built['payload'], $resolvedStatus);
        }

        foreach ($headers as $name => $value) {
            $response = $response->withHeader($name, $value);
        }

        return self::attachHtmxTrigger($request, $response, $built['toast']);
    }

    private static function normalizeDetails(string $code, array $details): array
    {
        if ($code === ErrorCode::VALIDATION_FAILED) {
            if (!array_key_exists('fields', $details)) {
                $details = ['fields' => $details];
            }
        }

        return $details;
    }

    private static function renderHtmlError(Request $request, array $payload, int $status): Response
    {
        $message = (string) (($payload['meta']['error']['message'] ?? '') ?: '');
        $errorKey = (string) (($payload['meta']['error']['key'] ?? '') ?: '');
        $requestId = (string) (($payload['meta']['request_id'] ?? '') ?: RequestContext::requestId());

        $view = RequestScope::get('view');
        if ($view instanceof View) {
            $template = 'pages/' . $status . '.html';
            $theme = str_starts_with($request->getPath(), '/admin') ? 'admin' : null;
            $options = $theme !== null ? ['theme' => $theme] : [];
            $backUrl = self::resolveBackUrl($request);

            try {
                return $view->render($template, [
                    'message' => $message,
                    'error_key' => $errorKey,
                    'status' => $status,
                    'back_url' => $backUrl,
                    'request_id' => $requestId,
                ], $status, [], $options);
            } catch (\Throwable) {
            }
        }

        $body = $message !== '' ? $message : 'Error';
        return new Response($body, $status, [
            'Content-Type' => 'text/plain; charset=utf-8',
        ]);
    }

    private static function attachHtmxTrigger(?Request $request, Response $response, array $toast): Response
    {
        if ($request === null || !$request->isHtmx() || $request->wantsJson()) {
            return $response;
        }

        if ($toast === []) {
            return $response;
        }

        return HtmxTrigger::addToast($response, $toast);
    }

    /**
     * @return array<string, mixed>
     */
    private static function registerErrorToast(array $payload): array
    {
        $errorKey = (string) ($payload['meta']['error']['key'] ?? '');
        $message = (string) ($payload['meta']['error']['message'] ?? '');
        if ($errorKey === '' && $message === '') {
            return [];
        }

        $toastKey = $errorKey === 'error.validation_failed' ? 'toast.validation_failed' : $errorKey;

        $ttlMs = 8000;
        $dedupeKey = $toastKey !== '' ? $toastKey : null;

        return UiToast::registerDanger($message, $toastKey, $ttlMs, null, $dedupeKey);
    }

    private static function resolveBackUrl(Request $request): ?string
    {
        $referer = (string) ($request->getHeader('referer') ?? '');
        if ($referer === '') {
            return null;
        }

        $parts = parse_url($referer);
        if (!is_array($parts)) {
            return null;
        }

        $path = $parts['path'] ?? '';
        if (!is_string($path) || $path === '') {
            return null;
        }

        $host = $parts['host'] ?? null;
        if ($host !== null) {
            $host = strtolower((string) $host);
            $requestHost = strtolower((string) ($request->getHeader('host') ?? ''));
            if ($requestHost !== '' && $host !== $requestHost) {
                return null;
            }
        }

        $query = $parts['query'] ?? null;
        if (is_string($query) && $query !== '') {
            return $path . '?' . $query;
        }

        return $path;
    }

    private static function attachSource(array $details, ?string $source): array
    {
        if ($source === null || $source === '') {
            return $details;
        }

        if (!self::isDebug()) {
            return $details;
        }

        $details['source'] = $source;

        return $details;
    }

    private static function isSecurityError(string $code): bool
    {
        return in_array($code, self::SECURITY_ERRORS, true);
    }

    private static function isDebug(): bool
    {
        $env = $_ENV['APP_DEBUG'] ?? getenv('APP_DEBUG') ?: null;
        if ($env !== null && $env !== '') {
            $parsed = filter_var($env, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($parsed !== null) {
                return $parsed;
            }
        }

        $config = self::appConfig();
        return (bool) ($config['debug'] ?? false);
    }

    private static function translate(string $key, ?Request $request): string
    {
        $translator = self::translator();
        $locale = self::resolveLocale($request);
        return $translator->trans($key, [], $locale);
    }

    private static function resolveLocale(?Request $request): string
    {
        $config = self::appConfig();
        $default = (string) ($config['default_locale'] ?? 'en');
        if ($request === null) {
            return $default;
        }

        $resolver = self::$localeResolver ??= new LocaleResolver($config);
        $resolved = $resolver->resolve($request);
        $locale = (string) ($resolved['locale'] ?? $default);

        return $locale !== '' ? $locale : $default;
    }

    private static function translator(): Translator
    {
        if (self::$translator instanceof Translator) {
            return self::$translator;
        }

        $config = self::appConfig();
        $rootPath = dirname(__DIR__, 2);
        $theme = (string) ($config['theme'] ?? 'default');
        $default = (string) ($config['default_locale'] ?? 'en');
        self::$translator = new Translator($rootPath, $theme, $default);

        return self::$translator;
    }

    private static function appConfig(): array
    {
        if (self::$appConfig !== null) {
            return self::$appConfig;
        }

        $path = dirname(__DIR__, 2) . '/config/app.php';
        $config = is_file($path) ? require $path : [];
        self::$appConfig = is_array($config) ? $config : [];

        return self::$appConfig;
    }

    /**
     * @return array{type: string, title: string, status: int, instance: string, detail?: string}
     */
    private static function buildProblemDetails(
        ?Request $request,
        string $errorKey,
        string $message,
        int $status,
        array $details,
        ?string $source,
        array $meta
    ): array {
        $requestId = (string) ($meta['request_id'] ?? RequestContext::requestId());
        $problem = [
            'type' => 'laas:problem/' . $errorKey,
            'title' => self::resolveProblemTitle($errorKey, $message, $request),
            'status' => $status,
            'instance' => $requestId,
        ];

        if (self::isDebug()) {
            $detail = self::resolveProblemDetail($source, $details);
            if ($detail !== '') {
                $problem['detail'] = $detail;
            }
        }

        return $problem;
    }

    private static function resolveProblemTitle(string $errorKey, string $message, ?Request $request): string
    {
        $translator = self::translator();
        $locale = self::resolveLocale($request);
        $key = 'error.title.' . $errorKey;
        if ($translator->has($key, $locale)) {
            return $translator->trans($key, [], $locale);
        }
        if ($translator->has('error.title.default', $locale)) {
            return $translator->trans('error.title.default', [], $locale);
        }

        return $message;
    }

    private static function resolveProblemDetail(?string $source, array $details): string
    {
        if ($source !== null && $source !== '') {
            return $source;
        }
        if ($details === []) {
            return '';
        }

        $json = json_encode($details, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return $json !== false ? $json : '';
    }
}
