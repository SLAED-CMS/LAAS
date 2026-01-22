<?php

declare(strict_types=1);

namespace Laas\Content\Blocks;

use RuntimeException;

final class BlockValidationException extends RuntimeException
{
    /** @var array<int, array{index: int, field: string, message: string}> */
    private array $errors;

    /**
     * @param array<int, array{index: int, field: string, message: string}> $errors
     */
    public function __construct(array $errors)
    {
        parent::__construct('Block validation failed');
        $this->errors = $errors;
    }

    /**
     * @return array<int, array{index: int, field: string, message: string}>
     */
    public function getErrors(): array
    {
        return $this->errors;
    }
}
