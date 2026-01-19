<?php
declare(strict_types=1);

namespace Laas\Domain\ApiTokens;

use RuntimeException;

final class ApiTokensServiceException extends RuntimeException
{
    /** @param array<string, mixed> $details */
    public function __construct(
        private string $errorCode,
        private array $details = [],
        string $message = ''
    ) {
        parent::__construct($message !== '' ? $message : $errorCode);
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    /** @return array<string, mixed> */
    public function details(): array
    {
        return $this->details;
    }
}
