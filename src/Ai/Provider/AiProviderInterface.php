<?php

declare(strict_types=1);

namespace Laas\Ai\Provider;

interface AiProviderInterface
{
    /**
     * @param array<string, mixed> $input
     * @return array{proposal: array<string, mixed>, plan: array<string, mixed>}
     */
    public function propose(array $input): array;
}
