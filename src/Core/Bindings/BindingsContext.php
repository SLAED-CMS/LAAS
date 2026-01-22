<?php

declare(strict_types=1);

namespace Laas\Core\Bindings;

use Laas\Core\Kernel;
use Laas\Database\DatabaseManager;
use RuntimeException;

final class BindingsContext
{
    private static ?Kernel $kernel = null;
    private static array $config = [];
    private static string $rootPath = '';

    public static function set(Kernel $kernel, array $config, string $rootPath): void
    {
        self::$kernel = $kernel;
        self::$config = $config;
        self::$rootPath = $rootPath;
    }

    /**
     * @return array<string, mixed>
     */
    public static function config(): array
    {
        return self::$config;
    }

    public static function rootPath(): string
    {
        if (self::$rootPath === '') {
            throw new RuntimeException('BindingsContext root path not initialized.');
        }
        return self::$rootPath;
    }

    public static function database(): DatabaseManager
    {
        return self::kernel()->database();
    }

    private static function kernel(): Kernel
    {
        if (self::$kernel === null) {
            throw new RuntimeException('BindingsContext kernel not initialized.');
        }
        return self::$kernel;
    }
}
