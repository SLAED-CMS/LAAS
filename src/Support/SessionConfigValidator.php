<?php
declare(strict_types=1);

namespace Laas\Support;

final class SessionConfigValidator
{
    /** @return array<int, string> */
    public function warnings(array $sessionConfig): array
    {
        $warnings = [];

        $driver = strtolower(trim((string) ($sessionConfig['driver'] ?? 'native')));
        if ($driver !== '' && !in_array($driver, ['native', 'redis'], true)) {
            $this->addWarning($warnings, 'session.driver invalid');
        }
        $rawDriver = strtolower(trim($this->envValue('SESSION_DRIVER')));
        if ($rawDriver !== '' && !in_array($rawDriver, ['native', 'redis'], true)) {
            $this->addWarning($warnings, 'session.driver invalid');
        }

        $samesiteRaw = (string) ($sessionConfig['cookie_samesite'] ?? $sessionConfig['samesite'] ?? 'Lax');
        if ($samesiteRaw !== '') {
            $samesite = ucfirst(strtolower($samesiteRaw));
            if (!in_array($samesite, ['Lax', 'Strict', 'None'], true)) {
                $this->addWarning($warnings, 'session.cookie_samesite invalid');
            }
        }
        $rawSameSite = $this->envValue('SESSION_COOKIE_SAMESITE');
        if ($rawSameSite === '') {
            $rawSameSite = $this->envValue('SESSION_SAMESITE');
        }
        if ($rawSameSite !== '') {
            $normalized = ucfirst(strtolower(trim($rawSameSite)));
            if (!in_array($normalized, ['Lax', 'Strict', 'None'], true)) {
                $this->addWarning($warnings, 'session.cookie_samesite invalid');
            }
        }

        $idle = (int) ($sessionConfig['idle_ttl'] ?? 0);
        $absolute = (int) ($sessionConfig['absolute_ttl'] ?? 0);
        if ($idle < 0) {
            $this->addWarning($warnings, 'session.idle_ttl invalid');
        }
        if ($absolute < 0) {
            $this->addWarning($warnings, 'session.absolute_ttl invalid');
        }
        if ($absolute > 0 && $idle > 0 && $idle > $absolute) {
            $this->addWarning($warnings, 'session.ttl_mismatch');
        }

        $rawIdle = $this->envValue('SESSION_IDLE_TTL');
        if ($rawIdle !== '' && !is_numeric($rawIdle)) {
            $this->addWarning($warnings, 'session.idle_ttl invalid');
        }
        $rawAbsolute = $this->envValue('SESSION_ABSOLUTE_TTL');
        if ($rawAbsolute !== '' && !is_numeric($rawAbsolute)) {
            $this->addWarning($warnings, 'session.absolute_ttl invalid');
        }

        $secure = $sessionConfig['cookie_secure'] ?? $sessionConfig['secure'] ?? null;
        if ($secure !== null && !is_bool($secure)) {
            $this->addWarning($warnings, 'session.cookie_secure invalid');
        }

        $rawSecure = $this->envValue('SESSION_COOKIE_SECURE');
        if ($rawSecure === '') {
            $rawSecure = $this->envValue('SESSION_SECURE');
        }
        if ($rawSecure !== '') {
            $parsed = filter_var($rawSecure, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($parsed === null) {
                $this->addWarning($warnings, 'session.cookie_secure invalid');
            }
        }

        return $warnings;
    }

    /** @param array<int, string> $warnings */
    private function addWarning(array &$warnings, string $message): void
    {
        if (!in_array($message, $warnings, true)) {
            $warnings[] = $message;
        }
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
