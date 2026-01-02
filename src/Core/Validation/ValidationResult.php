<?php
declare(strict_types=1);

namespace Laas\Core\Validation;

final class ValidationResult
{
    /** @var array<string, array<int, array{key: string, params: array<string, mixed>}>> */
    private array $errors = [];

    public function addError(string $field, string $key, array $params = []): void
    {
        $this->errors[$field][] = [
            'key' => $key,
            'params' => $params,
        ];
    }

    public function isValid(): bool
    {
        return $this->errors === [];
    }

    /** @return array<string, array<int, array{key: string, params: array<string, mixed>}>> */
    public function errors(): array
    {
        return $this->errors;
    }

    public function first(string $field): ?string
    {
        if (!isset($this->errors[$field][0]['key'])) {
            return null;
        }

        return (string) $this->errors[$field][0]['key'];
    }
}
