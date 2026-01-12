<?php
declare(strict_types=1);

namespace Laas\Http\Contract;

final class ContractRegistry
{
    private static array $contracts = [];
    private static bool $bootstrapped = false;

    public static function register(string $name, array $spec): void
    {
        self::boot();
        if (!array_key_exists('name', $spec)) {
            $spec['name'] = $name;
        }
        self::$contracts[$name] = $spec;
    }

    /** @return array<int, array<string, mixed>> */
    public static function all(): array
    {
        self::boot();
        return array_values(self::$contracts);
    }

    private static function boot(): void
    {
        if (self::$bootstrapped) {
            return;
        }
        self::$bootstrapped = true;

        $path = __DIR__ . '/contracts.php';
        if (is_file($path)) {
            require $path;
        }
    }
}
