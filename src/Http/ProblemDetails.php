<?php

declare(strict_types=1);

namespace Laas\Http;

use Laas\Core\Validation\ValidationResult;

final class ProblemDetails
{
    public function __construct(
        private string $type = 'about:blank',
        private string $title = 'Error',
        private int $status = 500,
        private string $detail = '',
        private string $instance = '',
        private string $errorId = '',
        private array $errors = []
    ) {
    }

    public static function internalError(Request $request, string $errorId): self
    {
        return new self(
            'about:blank',
            'Internal Server Error',
            500,
            'Unexpected error',
            $request->getPath(),
            $errorId,
            []
        );
    }

    public static function validationFailed(Request $request, ValidationResult $result, string $errorId): self
    {
        return new self(
            'about:blank',
            'Validation failed',
            422,
            'Validation failed',
            $request->getPath(),
            $errorId,
            $result->toErrorMap()
        );
    }

    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'title' => $this->title,
            'status' => $this->status,
            'detail' => $this->detail,
            'instance' => $this->instance,
            'error_id' => $this->errorId,
            'errors' => $this->errors,
        ];
    }
}
