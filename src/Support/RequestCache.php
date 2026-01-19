<?php
declare(strict_types=1);

namespace Laas\Support;

final class RequestCache
{
    public static function remember(string $key, callable $resolver): mixed
    {
        if (RequestScope::getRequest() === null) {
            return $resolver();
        }

        if (RequestScope::has($key)) {
            return RequestScope::get($key);
        }

        $value = $resolver();
        RequestScope::set($key, $value);

        return $value;
    }
}
