<?php

declare(strict_types=1);

namespace Laas\Admin\Editors;

interface EditorProviderInterface
{
    public function id(): string;

    /**
     * @return string "html" or "markdown"
     */
    public function format(): string;

    public function label(): string;

    public function isAvailable(): bool;

    /**
     * @return array{js: string, css: string}
     */
    public function assets(): array;
}
