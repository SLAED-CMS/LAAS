<?php

declare(strict_types=1);

namespace Laas\Admin\Editors;

final class TextareaProvider implements EditorProviderInterface
{
    public function id(): string
    {
        return 'textarea';
    }

    public function format(): string
    {
        return 'html';
    }

    public function label(): string
    {
        return 'Plain textarea';
    }

    public function isAvailable(): bool
    {
        return true;
    }

    public function config(): array
    {
        return [];
    }

    public function assets(): array
    {
        return ['js' => '', 'css' => ''];
    }
}
