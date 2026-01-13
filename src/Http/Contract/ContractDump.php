<?php
declare(strict_types=1);

namespace Laas\Http\Contract;

final class ContractDump
{
    /** @return array<string, mixed> */
    public static function build(string $appVersion): array
    {
        $items = ContractRegistry::all();
        usort($items, static function (array $a, array $b): int {
            $nameA = is_string($a['name'] ?? null) ? $a['name'] : '';
            $nameB = is_string($b['name'] ?? null) ? $b['name'] : '';
            return strcmp($nameA, $nameB);
        });

        return [
            'contracts_version' => ContractRegistry::version(),
            'app_version' => $appVersion,
            'items' => $items,
        ];
    }
}
