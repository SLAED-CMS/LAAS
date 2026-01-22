<?php

declare(strict_types=1);

namespace Laas\DevTools;

final class TerminalFormatter
{
    public static function formatPromptLine(string $method, string $path, int $status, float $totalMs, float $memoryMb, string $requestId): string
    {
        $method = strtoupper($method);
        $path = $path !== '' ? $path : '/';
        return sprintf(
            'laas> %s %s  %d  %.2fms  mem %.0fMB  rid %s',
            $method,
            $path,
            $status,
            $totalMs,
            $memoryMb,
            $requestId
        );
    }

    public static function formatSummarySegment(string $label, string $value): string
    {
        return sprintf('%s %s', $label, $value);
    }

    public static function formatWarningsLine(array $tokens): string
    {
        if ($tokens === []) {
            return 'WARN  OK';
        }
        return 'WARN  ' . implode('  ', $tokens);
    }

    public static function formatTimelineLine(float $sqlPct, float $httpPct, float $othPct, float $sqlMs, float $httpMs, float $othMs): string
    {
        return sprintf(
            'TIME [SQL %.0f%%][HTTP %.0f%%][OTH %.0f%%]  SQL %.1fms  HTTP %.1fms  OTH %.1fms',
            $sqlPct,
            $httpPct,
            $othPct,
            $sqlMs,
            $httpMs,
            $othMs
        );
    }

    public static function formatOffenderLine(string $marker, string $type, string $detail, string $value): string
    {
        $mark = $marker === '!' ? '!' : ' ';
        $type = substr($type, 0, 4);
        return sprintf('%1s %-4s %-26s %s', $mark, $type, $detail, $value);
    }
}
