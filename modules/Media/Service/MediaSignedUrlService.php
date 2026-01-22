<?php

declare(strict_types=1);

namespace Laas\Modules\Media\Service;

final class MediaSignedUrlService
{
    private bool $enabled;
    private int $ttl;
    private string $secret;

    public function __construct(array $config)
    {
        $this->enabled = (bool) ($config['signed_urls_enabled'] ?? false);
        $this->ttl = (int) ($config['signed_url_ttl'] ?? 600);
        $this->secret = (string) ($config['signed_url_secret'] ?? '');
    }

    public function isEnabled(): bool
    {
        return $this->enabled && $this->secret !== '';
    }

    public function ttl(): int
    {
        return $this->ttl > 0 ? $this->ttl : 600;
    }

    /** @return array{valid: bool, exp?: int, reason?: string} */
    public function validate(array $media, string $purpose, ?string $exp, ?string $sig): array
    {
        if (!$this->isEnabled()) {
            return ['valid' => false, 'reason' => 'disabled'];
        }

        if ($purpose === '' || $exp === null || $sig === null || $sig === '') {
            return ['valid' => false, 'reason' => 'invalid'];
        }

        if (!ctype_digit($exp)) {
            return ['valid' => false, 'reason' => 'invalid'];
        }

        $expiresAt = (int) $exp;
        $now = time();
        if ($expiresAt < $now) {
            return ['valid' => false, 'reason' => 'expired', 'exp' => $expiresAt];
        }

        $id = (int) ($media['id'] ?? 0);
        if ($id <= 0) {
            return ['valid' => false, 'reason' => 'invalid'];
        }

        $token = (string) ($media['public_token'] ?? '');
        $expected = $this->sign($id, $expiresAt, $purpose, $token);
        if (!$this->signaturesEqual($expected, (string) $sig)) {
            return ['valid' => false, 'reason' => 'invalid', 'exp' => $expiresAt];
        }

        return ['valid' => true, 'exp' => $expiresAt];
    }

    public function buildSignedUrl(string $path, array $media, string $purpose, ?int $expiresAt = null): ?string
    {
        if (!$this->isEnabled()) {
            return null;
        }

        $id = (int) ($media['id'] ?? 0);
        if ($id <= 0) {
            return null;
        }

        $exp = $expiresAt ?? (time() + $this->ttl());
        $token = (string) ($media['public_token'] ?? '');
        $sig = $this->sign($id, $exp, $purpose, $token);

        return sprintf('%s?exp=%d&sig=%s&p=%s', $path, $exp, $sig, rawurlencode($purpose));
    }

    public function signaturesEqual(string $a, string $b): bool
    {
        if ($a === '' || $b === '') {
            return false;
        }

        return hash_equals($a, $b);
    }

    private function sign(int $id, int $expiresAt, string $purpose, string $token): string
    {
        $payload = $id . '|' . $expiresAt . '|' . $purpose . '|' . $token;
        return hash_hmac('sha256', $payload, $this->secret);
    }
}
