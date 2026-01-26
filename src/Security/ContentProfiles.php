<?php

declare(strict_types=1);

namespace Laas\Security;

final class ContentProfiles
{
    public const ADMIN_TRUSTED_RAW = 'admin_trusted_raw';
    public const EDITOR_SAFE_RICH = 'editor_safe_rich';
    public const USER_PLAIN = 'user_plain';
    public const LEGACY = 'legacy';

    /**
     * @return array<int, string>
     */
    public static function all(): array
    {
        return [
            self::ADMIN_TRUSTED_RAW,
            self::EDITOR_SAFE_RICH,
            self::USER_PLAIN,
        ];
    }

    public static function resolve(?string $profile): string
    {
        $profile = strtolower(trim((string) $profile));
        if ($profile === '') {
            return self::LEGACY;
        }

        $allowed = array_merge(self::all(), [self::LEGACY]);
        return in_array($profile, $allowed, true) ? $profile : self::LEGACY;
    }
}
