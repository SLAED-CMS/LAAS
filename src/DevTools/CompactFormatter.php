<?php

declare(strict_types=1);

namespace Laas\DevTools;

final class CompactFormatter
{
    public static function formatOffenderLine(string $marker, string $type, string $value, string $desc): string
    {
        $mark = $marker === '!' ? '!' : ' ';
        $type = substr($type, 0, 4);
        $value = substr($value, 0, 8);
        return sprintf('%1s %-4s %8s  %s', $mark, $type, $value, $desc);
    }
}
