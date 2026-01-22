<?php

declare(strict_types=1);

namespace Laas\Support\Cache;

final class CacheKey
{
    private const VERSION = 1;

    public static function settingsAll(): string
    {
        return 'settings:v' . self::VERSION . ':all';
    }

    public static function settingsKey(string $name): string
    {
        return 'settings:v' . self::VERSION . ':key:' . $name;
    }

    public static function menu(string $name, string $locale): string
    {
        return 'menus:v' . self::VERSION . ':' . $name . ':' . $locale;
    }

    public static function permissionsRole(int $roleId): string
    {
        return 'permissions:v' . self::VERSION . ':role:' . $roleId;
    }

    public static function permissionsUser(int $userId): string
    {
        return 'permissions:v' . self::VERSION . ':user:' . $userId;
    }

    public static function sessionRbacVersion(int $userId): string
    {
        return 'session:v' . self::VERSION . ':rbac:' . $userId;
    }
}
