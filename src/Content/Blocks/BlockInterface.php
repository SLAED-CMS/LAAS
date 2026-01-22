<?php

declare(strict_types=1);

namespace Laas\Content\Blocks;

interface BlockInterface
{
    public function getType(): string;

    /**
     * @param array<string, mixed> $data
     */
    public function validate(array $data): void;

    /**
     * @param array<string, mixed> $data
     */
    public function renderHtml(array $data, ThemeContext $ctx): string;

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function renderJson(array $data): array;
}
