<?php

declare(strict_types=1);

namespace Laas\Domain\Settings;

interface SettingsReadServiceInterface
{
    /** @return array<string, mixed> */
    public function defaultSettings(): array;

    /** @return array<int, string> */
    public function availableLocales(): array;

    /** @return array<int, string> */
    public function availableThemes(): array;

    /** @return array{settings: array<string, mixed>, sources: array<string, string>} */
    public function settingsWithSources(): array;

    public function get(string $key, mixed $default = null): mixed;

    public function has(string $key): bool;

    /** @return array<string, string> */
    public function sources(array $keys): array;
}
