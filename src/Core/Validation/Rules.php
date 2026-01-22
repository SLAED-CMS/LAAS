<?php

declare(strict_types=1);

namespace Laas\Core\Validation;

final class Rules
{
    /** @return array<string, string> */
    public static function fieldLabelKeys(): array
    {
        return [
            'pages.title' => 'fields.pages.title',
            'pages.slug' => 'fields.pages.slug',
            'auth.username' => 'fields.auth.username',
            'auth.password' => 'fields.auth.password',
        ];
    }

    public static function slugPattern(): string
    {
        return '/^[a-z0-9-]+$/';
    }
}
