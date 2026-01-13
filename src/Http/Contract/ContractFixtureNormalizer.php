<?php
declare(strict_types=1);

namespace Laas\Http\Contract;

final class ContractFixtureNormalizer
{
    private const TIME_KEYS = ['created_at', 'updated_at'];
    private const ID_KEYS = [
        'id',
        'user_id',
        'role_id',
        'permission_id',
        'media_id',
        'page_id',
        'module_id',
        'token_id',
    ];

    /** @return array<string, mixed> */
    public static function normalize(array $payload): array
    {
        $normalized = self::normalizeValue($payload, '');
        return is_array($normalized) ? $normalized : [];
    }

    private static function normalizeValue(mixed $value, string $key): mixed
    {
        if (is_array($value)) {
            $out = [];
            foreach ($value as $childKey => $childValue) {
                $childKeyStr = is_string($childKey) ? $childKey : (string) $childKey;
                $out[$childKey] = self::normalizeValue($childValue, $childKeyStr);
            }
            return $out;
        }

        if ($key === 'request_id') {
            return '__REQ__';
        }

        if (in_array($key, self::TIME_KEYS, true)) {
            return '__TIME__';
        }

        if (in_array($key, self::ID_KEYS, true) && self::isNumericId($value)) {
            return '__ID__';
        }

        return $value;
    }

    private static function isNumericId(mixed $value): bool
    {
        if (is_int($value)) {
            return true;
        }
        if (is_string($value) && $value !== '' && ctype_digit($value)) {
            return true;
        }
        return false;
    }
}
