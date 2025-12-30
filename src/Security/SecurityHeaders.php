<?php
declare(strict_types=1);

namespace Laas\Security;

final class SecurityHeaders
{
    public function __construct(private array $config)
    {
    }

    /** @return array<string, string> */
    public function all(): array
    {
        $headers = [
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => $this->config['referrer_policy'] ?? 'strict-origin-when-cross-origin',
            'Permissions-Policy' => $this->config['permissions_policy'] ?? 'geolocation=(), microphone=(), camera=()',
        ];

        $frameOptions = $this->config['frame_options'] ?? 'DENY';
        if ($frameOptions !== '') {
            $headers['X-Frame-Options'] = $frameOptions;
        }

        $csp = $this->buildCsp($this->config['csp'] ?? []);
        if ($csp !== '') {
            $headers['Content-Security-Policy'] = $csp;
        }

        if (!empty($this->config['hsts_enabled'])) {
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
}
