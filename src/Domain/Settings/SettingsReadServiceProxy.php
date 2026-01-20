<?php
declare(strict_types=1);

namespace Laas\Domain\Settings;

use Laas\Domain\Support\ReadOnlyProxy;

final class SettingsReadServiceProxy extends ReadOnlyProxy implements SettingsReadServiceInterface
{
    /** @return array<string, mixed> */
    public function defaultSettings(): array
    {
        return $this->call(__FUNCTION__, func_get_args());
    }

    /** @return array<int, string> */
    public function availableLocales(): array
    {
        return $this->call(__FUNCTION__, func_get_args());
    }

    /** @return array<int, string> */
    public function availableThemes(): array
    {
        return $this->call(__FUNCTION__, func_get_args());
    }

    /** @return array{settings: array<string, mixed>, sources: array<string, string>} */
    public function settingsWithSources(): array
    {
        return $this->call(__FUNCTION__, func_get_args());
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->call(__FUNCTION__, func_get_args());
    }

    public function has(string $key): bool
    {
        return $this->call(__FUNCTION__, func_get_args());
    }

    /** @return array<string, string> */
    public function sources(array $keys): array
    {
        return $this->call(__FUNCTION__, func_get_args());
    }
}
