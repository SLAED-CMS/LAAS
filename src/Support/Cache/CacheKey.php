<?php
declare(strict_types=1);

namespace Laas\Support\Cache;

final class CacheKey
{
    private const VERSION = 1;

    public static function settingsAll(): string
    {
        return 'settings.all.v' . self::VERSION;
    }

    public static function settingsKey(string $name): string
    {
        return 'settings.key.' . $name . '.v' . self::VERSION;
    }

    public static function menu(string $name, string $locale): string
    {
        return 'menu.' . $name . '.' . $locale . '.v' . self::VERSION;
    }
}
