<?php

declare(strict_types=1);

namespace Laas\Routing;

use Laas\Core\Container\Container;
use ReflectionNamedType;
use ReflectionParameter;

final class RouteHandlerTokens
{
    public const TOKEN_VIEW = 'view';
    public const TOKEN_CONTAINER = 'container';
    public const TOKEN_DB = 'db';
    public const TOKEN_NULL = 'null';

    /**
     * @return array<int, string>
     */
    public static function viewOnly(): array
    {
        return [self::TOKEN_VIEW];
    }

    /**
     * @return array<int, string>
     */
    public static function fromParamCountTail(int $paramCount, bool $useContainer): array
    {
        if ($useContainer && $paramCount >= 4) {
            return [self::TOKEN_VIEW, self::TOKEN_NULL, self::TOKEN_NULL, self::TOKEN_CONTAINER];
        }
        if ($useContainer && $paramCount >= 3) {
            return [self::TOKEN_VIEW, self::TOKEN_NULL, self::TOKEN_CONTAINER];
        }
        if ($useContainer && $paramCount >= 2) {
            return [self::TOKEN_VIEW, self::TOKEN_CONTAINER];
        }
        if ($paramCount >= 2) {
            return [self::TOKEN_VIEW, self::TOKEN_NULL];
        }
        return [self::TOKEN_VIEW];
    }

    /**
     * @return array<int, string>
     */
    public static function fromParamCountApi(int $paramCount, bool $useContainer): array
    {
        if ($useContainer && $paramCount >= 4) {
            return [self::TOKEN_VIEW, self::TOKEN_NULL, self::TOKEN_CONTAINER, self::TOKEN_NULL];
        }
        if ($useContainer && $paramCount >= 3) {
            return [self::TOKEN_VIEW, self::TOKEN_NULL, self::TOKEN_CONTAINER];
        }
        if ($useContainer && $paramCount >= 2) {
            return [self::TOKEN_VIEW, self::TOKEN_CONTAINER];
        }
        if ($paramCount >= 2) {
            return [self::TOKEN_VIEW, self::TOKEN_NULL];
        }
        return [self::TOKEN_VIEW];
    }

    /**
     * @param array<int, ReflectionParameter> $params
     * @return array<int, string>
     */
    public static function fromParams(array $params, int $paramCount, bool $useContainer): array
    {
        if ($paramCount <= 0) {
            return [];
        }

        $tokens = array_fill(0, $paramCount, self::TOKEN_NULL);
        $tokens[0] = self::TOKEN_VIEW;

        if ($useContainer && $params !== []) {
            foreach ($params as $index => $param) {
                $type = $param->getType();
                if ($type instanceof ReflectionNamedType && $type->getName() === Container::class) {
                    $tokens[$index] = self::TOKEN_CONTAINER;
                }
            }
        }

        return $tokens;
    }

    /**
     * @param array<int, string> $tokens
     * @param array<string, mixed> $context
     * @return array<int, mixed>
     */
    public static function resolve(array $tokens, array $context): array
    {
        $view = $context['view'] ?? null;
        $container = $context['container'] ?? null;
        $db = $context['db'] ?? null;

        $args = [];
        foreach ($tokens as $token) {
            if ($token === self::TOKEN_VIEW) {
                $args[] = $view;
            } elseif ($token === self::TOKEN_CONTAINER) {
                $args[] = $container;
            } elseif ($token === self::TOKEN_DB) {
                $args[] = $db;
            } else {
                $args[] = null;
            }
        }

        return $args;
    }
}
