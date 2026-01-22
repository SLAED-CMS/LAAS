<?php

declare(strict_types=1);

namespace Laas\Ai;

use DateTimeImmutable;

final class ProposalValidator
{
    /**
     * @param array<string, mixed> $data
     * @return array<int, array{path: string, message: string}>
     */
    public function validate(array $data): array
    {
        $errors = [];
        $required = [
            'id',
            'created_at',
            'kind',
            'summary',
            'file_changes',
            'entity_changes',
            'warnings',
            'confidence',
            'risk',
        ];
        $allowed = array_flip($required);

        foreach ($required as $key) {
            if (!array_key_exists($key, $data)) {
                $errors[] = [
                    'path' => $key,
                    'message' => 'is required',
                ];
            }
        }

        foreach ($data as $key => $_value) {
            if (!isset($allowed[$key])) {
                $errors[] = [
                    'path' => (string) $key,
                    'message' => 'additional property not allowed',
                ];
            }
        }

        $id = $data['id'] ?? null;
        if (!is_string($id) || strlen($id) < 8) {
            $errors[] = [
                'path' => 'id',
                'message' => 'must be string with min length 8',
            ];
        }

        $createdAt = $data['created_at'] ?? null;
        if (!is_string($createdAt) || $createdAt === '' || DateTimeImmutable::createFromFormat(DATE_ATOM, $createdAt) === false) {
            $errors[] = [
                'path' => 'created_at',
                'message' => 'must be ISO8601 date-time',
            ];
        }

        $kind = $data['kind'] ?? null;
        if (!is_string($kind) || trim($kind) === '') {
            $errors[] = [
                'path' => 'kind',
                'message' => 'must be non-empty string',
            ];
        }

        $summary = $data['summary'] ?? null;
        if (!is_string($summary)) {
            $errors[] = [
                'path' => 'summary',
                'message' => 'must be string',
            ];
        }

        $confidence = $data['confidence'] ?? null;
        if (!is_numeric($confidence)) {
            $errors[] = [
                'path' => 'confidence',
                'message' => 'must be number between 0 and 1',
            ];
        } else {
            $value = (float) $confidence;
            if ($value < 0.0 || $value > 1.0) {
                $errors[] = [
                    'path' => 'confidence',
                    'message' => 'must be between 0 and 1',
                ];
            }
        }

        $risk = $data['risk'] ?? null;
        if (!is_string($risk) || !in_array($risk, ['low', 'medium', 'high'], true)) {
            $errors[] = [
                'path' => 'risk',
                'message' => 'must be low, medium, or high',
            ];
        }

        $fileChanges = $data['file_changes'] ?? null;
        if (!is_array($fileChanges)) {
            $errors[] = [
                'path' => 'file_changes',
                'message' => 'must be array',
            ];
        } else {
            foreach ($fileChanges as $index => $item) {
                if (!is_array($item)) {
                    $errors[] = [
                        'path' => "file_changes[{$index}]",
                        'message' => 'must be object',
                    ];
                    continue;
                }
                $errors = array_merge($errors, $this->validateFileChange($item, $index));
            }
        }

        $entityChanges = $data['entity_changes'] ?? null;
        if (!is_array($entityChanges)) {
            $errors[] = [
                'path' => 'entity_changes',
                'message' => 'must be array',
            ];
        }

        $warnings = $data['warnings'] ?? null;
        if (!is_array($warnings)) {
            $errors[] = [
                'path' => 'warnings',
                'message' => 'must be array',
            ];
        } else {
            foreach ($warnings as $index => $warning) {
                if (!is_string($warning)) {
                    $errors[] = [
                        'path' => "warnings[{$index}]",
                        'message' => 'must be string',
                    ];
                }
            }
        }

        return $errors;
    }

    /**
     * @param array<string, mixed> $item
     * @return array<int, array{path: string, message: string}>
     */
    private function validateFileChange(array $item, int $index): array
    {
        $errors = [];
        $allowedKeys = ['op' => true, 'path' => true, 'content' => true];
        foreach ($item as $key => $_value) {
            if (!isset($allowedKeys[$key])) {
                $errors[] = [
                    'path' => "file_changes[{$index}].{$key}",
                    'message' => 'additional property not allowed',
                ];
            }
        }

        if (!array_key_exists('op', $item)) {
            $errors[] = [
                'path' => "file_changes[{$index}].op",
                'message' => 'is required',
            ];
        }
        if (!array_key_exists('path', $item)) {
            $errors[] = [
                'path' => "file_changes[{$index}].path",
                'message' => 'is required',
            ];
        }

        $op = $item['op'] ?? null;
        if (!is_string($op) || !in_array($op, ['create', 'update', 'delete'], true)) {
            $errors[] = [
                'path' => "file_changes[{$index}].op",
                'message' => 'must be create, update, or delete',
            ];
        }

        $path = $item['path'] ?? null;
        if (!is_string($path) || trim($path) === '') {
            $errors[] = [
                'path' => "file_changes[{$index}].path",
                'message' => 'must be non-empty string',
            ];
        }

        if (in_array($op, ['create', 'update'], true)) {
            if (!array_key_exists('content', $item) || !is_string($item['content'])) {
                $errors[] = [
                    'path' => "file_changes[{$index}].content",
                    'message' => 'must be string for create/update',
                ];
            }
        } elseif ($op === 'delete' && array_key_exists('content', $item) && !is_string($item['content'])) {
            $errors[] = [
                'path' => "file_changes[{$index}].content",
                'message' => 'must be string when provided',
            ];
        }

        return $errors;
    }
}
