<?php

declare(strict_types=1);

namespace Laas\I18n;

use Laas\Http\Request;
use Laas\Settings\SettingsProvider;
use Laas\Support\RequestScope;

final class LocaleResolver
{
    private const COOKIE_NAME = 'laas_lang';

    public function __construct(
        private array $appConfig,
        private ?SettingsProvider $settingsProvider = null
    ) {
    }

    /** @return array{locale: string, set_cookie: bool} */
    public function resolve(Request $request): array
    {
        $allowed = $this->appConfig['locales'] ?? ['en'];
        $default = (string) ($this->appConfig['default_locale'] ?? 'en');
        if ($this->settingsProvider !== null) {
            $default = (string) $this->settingsProvider->get('default_locale', $default);
        }

        $requested = $request->query('lang');
        if ($requested !== null && in_array($requested, $allowed, true)) {
            return ['locale' => $requested, 'set_cookie' => true];
        }

        $cookie = $this->readCookie();
        if ($cookie !== null && in_array($cookie, $allowed, true)) {
            return ['locale' => $cookie, 'set_cookie' => false];
        }

        return ['locale' => $default, 'set_cookie' => false];
    }

    public function cookieHeader(string $locale, ?Request $request = null): string
    {
        $request ??= RequestScope::getRequest();
        $secure = $request?->isHttps() ?? false;

        $parts = [
            self::COOKIE_NAME . '=' . rawurlencode($locale),
            'Path=/',
            'SameSite=Lax',
            'HttpOnly',
        ];
        if ($secure) {
            $parts[] = 'Secure';
        }

        return implode('; ', $parts);
    }

    private function readCookie(): ?string
    {
        $value = $_COOKIE[self::COOKIE_NAME] ?? null;
        if ($value === null || $value === '') {
            return null;
        }

        return (string) $value;
    }
}
