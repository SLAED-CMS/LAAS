<?php

declare(strict_types=1);

namespace Laas\DevTools;

final class BudgetClassifier
{
    /** @return array{status: string, class: string} */
    public static function classify(float $value, float $warn, float $bad): array
    {
        if ($value >= $bad) {
            return ['status' => 'bad', 'class' => 'danger'];
        }
        if ($value >= $warn) {
            return ['status' => 'warn', 'class' => 'warning'];
        }
        return self::ok();
    }

    /** @return array{status: string, class: string} */
    public static function ok(): array
    {
        return ['status' => 'ok', 'class' => 'success'];
    }

    /** @return array{status: string, class: string} */
    public static function warn(): array
    {
        return ['status' => 'warn', 'class' => 'warning'];
    }

    /** @return array{status: string, class: string} */
    public static function na(): array
    {
        return ['status' => 'na', 'class' => 'secondary'];
    }
}
