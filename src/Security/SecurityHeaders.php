<?php

declare(strict_types=1);

namespace Laas\Security;

final class SecurityHeaders
{
    public function __construct(private array $config)
    {
    }

    /** @return array<string, string> */
    public function all(?bool $isHttps = null): array
    {
        $isHttps = $isHttps ?? true;
        $headers = [
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => $this->config['referrer_policy'] ?? 'strict-origin-when-cross-origin',
            'Permissions-Policy' => $this->config['permissions_policy'] ?? 'geolocation=(), microphone=(), camera=()',
        ];

        $frameOptions = $this->config['frame_options'] ?? 'DENY';
        if ($frameOptions !== '') {
            $headers['X-Frame-Options'] = $frameOptions;
        }

        $cspConfig = $this->config['csp'] ?? [];
        $csp = $this->buildCsp($cspConfig);
        if ($csp !== '') {
            $headers[$this->cspHeaderName($cspConfig)] = $csp;
        }

        if (!empty($this->config['hsts_enabled']) && $isHttps) {
            $maxAge = (int) ($this->config['hsts_max_age'] ?? 31536000);
            $headers['Strict-Transport-Security'] = 'max-age=' . $maxAge;
        }

        return $headers;
    }

    private function buildCsp(array $csp): string
    {
        if (!($csp['enabled'] ?? true)) {
            return '';
        }

        $directives = $csp['directives'] ?? [];
        if ($directives === []) {
            return '';
        }

        $parts = [];
        foreach ($directives as $name => $values) {
            $items = is_array($values) ? $values : [$values];
            $parts[] = $name . ' ' . implode(' ', $items);
        }

        return implode('; ', $parts);
    }

    private function cspHeaderName(array $csp): string
    {
        $mode = strtolower(trim((string) ($csp['mode'] ?? 'enforce')));
        if ($mode === 'report-only') {
            return 'Content-Security-Policy-Report-Only';
        }

        return 'Content-Security-Policy';
    }
}
