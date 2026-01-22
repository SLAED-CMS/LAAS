<?php

declare(strict_types=1);

namespace Laas\Http;

final class RequestId
{
    public static function normalize(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim($value);
        if ($value === '') {
            return null;
        }

        if (preg_match('/^[a-zA-Z0-9._-]{8,80}$/', $value) !== 1) {
            return null;
        }

        return $value;
    }

    public static function generate(): string
    {
        return bin2hex(random_bytes(16));
    }

    public static function fromRequest(Request $request): string
    {
        $candidate = self::normalize($request->getHeader('x-request-id'));
        if ($candidate !== null) {
            return $candidate;
        }

        return self::generate();
    }
}
