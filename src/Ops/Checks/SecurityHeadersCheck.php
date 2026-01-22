<?php

declare(strict_types=1);

namespace Laas\Ops\Checks;

use Laas\Security\SecurityHeaders;

final class SecurityHeadersCheck
{
    public function __construct(
        private array $securityConfig,
        private ?bool $isHttps = null
    ) {
    }

    /** @return array{code: int, message: string, errors: array<int, string>, warnings: array<int, string>} */
    public function run(): array
    {
        $errors = [];
        $warnings = [];

        $securityHeaders = new SecurityHeaders($this->securityConfig);
        $headers = $securityHeaders->all($this->isHttps ?? true);

        foreach (['X-Content-Type-Options', 'X-Frame-Options', 'Referrer-Policy', 'Permissions-Policy'] as $name) {
            $value = $headers[$name] ?? '';
            if (!is_string($value) || trim($value) === '') {
                $errors[] = strtolower($name) . ' missing';
            }
        }

        $cspConfig = $this->securityConfig['csp'] ?? [];
        if (!is_array($cspConfig)) {
            $errors[] = 'csp config invalid';
            $cspConfig = [];
        }

        $cspEnabled = (bool) ($cspConfig['enabled'] ?? true);
        $mode = strtolower(trim((string) ($cspConfig['mode'] ?? 'enforce')));
        if (!in_array($mode, ['enforce', 'report-only'], true)) {
            $errors[] = 'csp mode invalid';
            $mode = 'enforce';
        }

        $directives = $cspConfig['directives'] ?? [];
        if ($cspEnabled) {
            if (!is_array($directives) || $directives === []) {
                $errors[] = 'csp directives empty';
            } else {
                $cspHeader = $mode === 'report-only'
                    ? 'Content-Security-Policy-Report-Only'
                    : 'Content-Security-Policy';
                $value = $headers[$cspHeader] ?? '';
                if (!is_string($value) || trim($value) === '') {
                    $errors[] = 'csp header missing';
                }
            }
        }

        $hstsEnabled = !empty($this->securityConfig['hsts_enabled']);
        if ($hstsEnabled) {
            if ($this->isHttps === false) {
                $warnings[] = 'hsts enabled without https';
            } elseif ($this->isHttps === true) {
                $value = $headers['Strict-Transport-Security'] ?? '';
                if (!is_string($value) || trim($value) === '') {
                    $errors[] = 'hsts header missing';
                }
            }
        }

        $code = 0;
        $status = 'OK';
        $details = '';
        if ($errors !== []) {
            $code = 1;
            $status = 'FAIL';
            $details = ' (' . implode(', ', $errors) . ')';
        } elseif ($warnings !== []) {
            $code = 2;
            $status = 'WARN';
            $details = ' (' . implode(', ', $warnings) . ')';
        }

        return [
            'code' => $code,
            'message' => 'security headers: ' . $status . $details,
            'errors' => $errors,
            'warnings' => $warnings,
        ];
    }
}
