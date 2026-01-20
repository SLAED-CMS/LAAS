<?php
declare(strict_types=1);

namespace Laas\Domain\Settings;

interface SettingsServiceInterface
{
    /** @return array<string, mixed> */
    public function defaultSettings(): array;

    /** @return array<int, string> */
    public function availableLocales(): array;

    /** @return array<int, string> */
    public function availableThemes(): array;

    /** @return array{settings: array<string, mixed>, sources: array<string, string>} */
    public function settingsWithSources(): array;

    public function setMany(array $pairs): void;

    /** @return array<string, string> */
    public function sources(array $keys): array;
}
