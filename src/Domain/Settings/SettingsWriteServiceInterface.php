<?php
declare(strict_types=1);

namespace Laas\Domain\Settings;

interface SettingsWriteServiceInterface
{
    /** @mutation */
    public function set(string $key, mixed $value): void;

    /** @mutation */
    public function setMany(array $pairs): void;
}
