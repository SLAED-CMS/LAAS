<?php
declare(strict_types=1);

namespace Laas\Ai\Context;

final class Redactor
{
    private const EMAIL_PATTERN = '/[A-Z0-9._%+-]+@[A-Z0-9.-]+\\.[A-Z]{2,}/i';
    private const LONG_HEX_PATTERN = '/\\b[a-f0-9]{32,}\\b/i';
    private const TOKEN_PATTERN = '/\\b(token|api_key|apikey|password|secret)\\s*[:=]\\s*([^\\s&]+)/i';
    private const BEARER_PATTERN = '/Authorization:\\s*Bearer\\s+[A-Za-z0-9._\\-]+/i';

    /**
     * @param array<int, string> $envKeys
     */
    public function __construct(
        private array $envKeys = [
            'DB_PASSWORD',
            'DB_PASS',
            'REDIS_PASSWORD',
            'REDIS_PASS',
            'API_TOKEN',
            'AUTH_TOKEN',
            'SECRET_KEY',
            'APP_KEY',
        ]
    ) {
    }

    public function redact(string $text): string
    {
        $text = preg_replace(self::BEARER_PATTERN, 'Authorization: Bearer <redacted_token>', $text) ?? $text;
        $text = preg_replace(self::TOKEN_PATTERN, '$1=<redacted>', $text) ?? $text;
        $text = preg_replace(self::EMAIL_PATTERN, '<redacted_email>', $text) ?? $text;
        $text = preg_replace(self::LONG_HEX_PATTERN, '<redacted_hex>', $text) ?? $text;

        foreach ($this->envKeys as $key) {
            $value = $this->envValue($key);
            if ($value !== '') {
                $text = str_replace($value, '<redacted_env>', $text);
            }
        }

        return $text;
    }

    private function envValue(string $key): string
    {
        $value = $_ENV[$key] ?? '';
        if ($value === '' && function_exists('getenv')) {
            $envValue = getenv($key);
            if ($envValue !== false) {
                $value = (string) $envValue;
            }
        }

        return (string) $value;
    }
}
