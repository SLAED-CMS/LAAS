<?php
declare(strict_types=1);

namespace Laas\Domain\Media;

use RuntimeException;

class MediaServiceException extends RuntimeException
{
    /** @param array<string, mixed> $params */
    public function __construct(private string $key, private array $params = [], ?\Throwable $previous = null)
    {
        parent::__construct($key, 0, $previous);
    }

    public function key(): string
    {
        return $this->key;
    }

    /** @return array<string, mixed> */
    public function params(): array
    {
        return $this->params;
    }
}
