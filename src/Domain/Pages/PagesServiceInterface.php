<?php
declare(strict_types=1);

namespace Laas\Domain\Pages;

interface PagesServiceInterface
{
    /** @return array<int, array<string, mixed>> */
    public function list(array $filters = []): array;
}
