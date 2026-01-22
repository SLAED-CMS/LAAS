<?php

declare(strict_types=1);

namespace Laas\Support\Search;

final class LikeEscaper
{
    public static function escape(string $value): string
    {
        if ($value === '') {
            return '';
        }

        $value = str_replace('\\', '\\\\', $value);
        $value = str_replace('%', '\\%', $value);
        $value = str_replace('_', '\\_', $value);

        return $value;
    }
}
