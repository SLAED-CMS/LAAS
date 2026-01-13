<?php
declare(strict_types=1);

namespace Laas\Http;

use Laas\I18n\LocaleResolver;
use Laas\I18n\Translator;
use Laas\Support\RequestScope;

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
     * @return array{payload: array<string, mixed>, status: int}
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

        return [
            'payload' => $payload,
            'status' => $status,
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

        return $response;
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
}
