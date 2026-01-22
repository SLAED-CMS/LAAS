<?php

declare(strict_types=1);

namespace Laas\Routing;

final class RouteHandlerSpec
{
    public const TYPE_CONTROLLER = 'controller';
    public const TYPE_MODULE = 'module';

    /**
     * @param array<int, string> $ctorTokens
     * @return array<string, mixed>
     */
    public static function controller(string $context, string $class, string $action, array $ctorTokens, bool $passVars): array
    {
        return [
            'type' => self::TYPE_CONTROLLER,
            'context' => $context,
            'class' => $class,
            'action' => $action,
            'ctor' => $ctorTokens,
            'pass_vars' => $passVars,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function module(string $context, string $class, string $action): array
    {
        return [
            'type' => self::TYPE_MODULE,
            'context' => $context,
            'class' => $class,
            'action' => $action,
        ];
    }
}
