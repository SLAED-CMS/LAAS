<?php

declare(strict_types=1);

namespace Laas\Core;

final class FeatureFlags implements FeatureFlagsInterface
{
    /**
     * @param array<string, mixed> $flags
     */
    public function __construct(private array $flags = [])
    {
    }

    public function isEnabled(string $flag): bool
    {
        if ($flag === '') {
            return false;
        }

        if (!array_key_exists($flag, $this->flags)) {
            return false;
        }

        return (bool) $this->flags[$flag];
    }

    /**
     * @return array<string, bool>
     */
    public function all(): array
    {
        $out = [];
        foreach ($this->flags as $key => $value) {
            if (!is_string($key) || $key === '') {
                continue;
            }
            $out[$key] = (bool) $value;
        }

        return $out;
    }
}
