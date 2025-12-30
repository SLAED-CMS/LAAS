<?php
declare(strict_types=1);

namespace Laas\Database\Repositories;

use PDO;

final class SettingsRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $stmt = $this->pdo->prepare('SELECT value, type FROM settings WHERE `key` = :key');
        $stmt->execute(['key' => $key]);
        $row = $stmt->fetch();
        if (!$row) {
            return $default;
        }

        return $this->deserialize((string) $row['value'], (string) $row['type']);
    }

    public function has(string $key): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM settings WHERE `key` = :key LIMIT 1');
        $stmt->execute(['key' => $key]);
        return (bool) $stmt->fetchColumn();
    }

    public function set(string $key, mixed $value, string $type = 'string'): void
    {
        $stored = $this->serialize($value, $type);
        $stmt = $this->pdo->prepare(
            'INSERT INTO settings (`key`, `value`, `type`, `updated_at`) VALUES (:key, :value, :type, NOW())
             ON DUPLICATE KEY UPDATE `value` = VALUES(`value`), `type` = VALUES(`type`), `updated_at` = NOW()'
        );
        $stmt->execute([
            'key' => $key,
            'value' => $stored,
            'type' => $type,
        ]);
    }

    private function serialize(mixed $value, string $type): string
    {
        return match ($type) {
            'bool' => $value ? '1' : '0',
            'int' => (string) ((int) $value),
            'json' => json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}',
            default => (string) $value,
        };
    }

    private function deserialize(string $value, string $type): mixed
    {
        return match ($type) {
            'bool' => $value === '1',
            'int' => (int) $value,
            'json' => json_decode($value, true),
            default => $value,
        };
    }
}
