<?php

declare(strict_types=1);

namespace Laas\Core\Validation;

use Laas\Database\DatabaseManager;

final class Validator
{
    /** @param array<string, mixed> $data */
    public function validate(array $data, array $rules, array $context = []): ValidationResult
    {
        $result = new ValidationResult();
        $translator = $context['translator'] ?? null;
        $labelPrefix = is_string($context['label_prefix'] ?? null) ? (string) $context['label_prefix'] : '';

        foreach ($rules as $field => $fieldRules) {
            $value = $data[$field] ?? null;
            $fieldLabel = $this->resolveFieldLabel($field, $labelPrefix, $translator);

            foreach ($this->normalizeRules($fieldRules) as $rule) {
                [$name, $params] = $this->parseRule($rule);

                if (!$this->passes($name, $value, $params, $context, $field)) {
                    $key = 'validation.' . $name;
                    $payload = array_merge(['field' => $fieldLabel], $this->normalizeParams($params));
                    $result->addError($field, $key, $payload);
                }
            }
        }

        return $result;
    }

    /** @return array<int, string> */
    private function normalizeRules(array|string $rules): array
    {
        if (is_array($rules)) {
            return array_values(array_filter($rules, 'is_string'));
        }

        $parts = array_map('trim', explode('|', $rules));
        return array_values(array_filter($parts, static fn (string $part): bool => $part !== ''));
    }

    /** @return array{0: string, 1: array<string, mixed>} */
    private function parseRule(string $rule): array
    {
        if (!str_contains($rule, ':')) {
            return [$rule, []];
        }

        [$name, $raw] = explode(':', $rule, 2);
        $name = trim($name);
        $raw = trim($raw);

        if ($name === 'min') {
            return [$name, ['min' => (int) $raw]];
        }

        if ($name === 'max') {
            return [$name, ['max' => (int) $raw]];
        }

        if ($name === 'in') {
            $values = array_map('trim', explode(',', $raw));
            return [$name, ['values' => $values]];
        }

        if ($name === 'reserved_slug') {
            $values = array_map('trim', explode(',', $raw));
            return [$name, ['values' => $values]];
        }

        if ($name === 'regex') {
            return [$name, ['pattern' => $raw]];
        }

        if ($name === 'unique') {
            $parts = array_map('trim', explode(',', $raw));
            $table = $parts[0] ?? '';
            $column = $parts[1] ?? '';
            $ignoreId = isset($parts[2]) && $parts[2] !== '' ? (int) $parts[2] : null;
            $idColumn = $parts[3] ?? 'id';

            return [$name, [
                'table' => $table,
                'column' => $column,
                'ignore_id' => $ignoreId,
                'id_column' => $idColumn,
            ]];
        }

        return [$name, []];
    }

    private function passes(string $name, mixed $value, array $params, array $context, string $field): bool
    {
        if ($name === 'required') {
            return $value !== null && $value !== '';
        }

        if ($value === null || $value === '') {
            return true;
        }

        return match ($name) {
            'string' => is_string($value),
            'min' => $this->checkMin((string) $value, (int) ($params['min'] ?? 0)),
            'max' => $this->checkMax((string) $value, (int) ($params['max'] ?? 0)),
            'regex' => $this->checkRegex((string) $value, (string) ($params['pattern'] ?? '')),
            'in' => $this->checkIn((string) $value, $params['values'] ?? []),
            'reserved_slug' => !$this->checkIn((string) $value, $params['values'] ?? []),
            'slug' => $this->checkRegex((string) $value, Rules::slugPattern()),
            'unique' => $this->checkUnique((string) $value, $params, $context),
            default => true,
        };
    }

    private function checkMin(string $value, int $min): bool
    {
        return mb_strlen($value) >= $min;
    }

    private function checkMax(string $value, int $max): bool
    {
        return mb_strlen($value) <= $max;
    }

    private function checkRegex(string $value, string $pattern): bool
    {
        if ($pattern === '') {
            return true;
        }

        return (bool) preg_match($pattern, $value);
    }

    /** @param array<int, string> $values */
    private function checkIn(string $value, array $values): bool
    {
        return in_array($value, $values, true);
    }

    private function checkUnique(string $value, array $params, array $context): bool
    {
        $table = (string) ($params['table'] ?? '');
        $column = (string) ($params['column'] ?? '');
        $ignoreId = $params['ignore_id'] ?? null;
        $idColumn = (string) ($params['id_column'] ?? 'id');

        if ($table === '' || $column === '') {
            return true;
        }

        if (!$this->isSafeIdentifier($table) || !$this->isSafeIdentifier($column) || !$this->isSafeIdentifier($idColumn)) {
            return false;
        }

        $db = $context['db'] ?? null;
        if (!$db instanceof DatabaseManager) {
            return true;
        }

        $sql = "SELECT 1 FROM {$table} WHERE {$column} = :value";
        $params = ['value' => $value];

        if ($ignoreId !== null) {
            $sql .= " AND {$idColumn} <> :ignore_id";
            $params['ignore_id'] = (int) $ignoreId;
        }

        $stmt = $db->pdo()->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchColumn() === false;
    }

    private function isSafeIdentifier(string $value): bool
    {
        return (bool) preg_match('/^[A-Za-z0-9_]+$/', $value);
    }

    private function resolveFieldLabel(string $field, string $prefix, mixed $translator): string
    {
        if ($prefix !== '') {
            $key = $prefix . '.' . $field;
            $labelKey = Rules::fieldLabelKeys()[$key] ?? '';
            if ($labelKey !== '' && is_object($translator) && method_exists($translator, 'trans')) {
                $label = (string) $translator->trans($labelKey);
                if ($label !== $labelKey) {
                    return $label;
                }
            }
        }

        return ucfirst($field);
    }

    /** @return array<string, mixed> */
    private function normalizeParams(array $params): array
    {
        if (isset($params['values']) && is_array($params['values'])) {
            $params['values'] = implode(', ', $params['values']);
        }

        return $params;
    }
}
