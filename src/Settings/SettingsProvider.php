<?php
declare(strict_types=1);

namespace Laas\Settings;

use Laas\Database\DatabaseManager;
use PDO;
use Throwable;

final class SettingsProvider
{
    private bool $loaded = false;
    private bool $dbFailed = false;
    private array $values = [];
    private array $sources = [];

    /** @param array<string, mixed> $defaults */
    public function __construct(
        private DatabaseManager $db,
        private array $defaults,
        private array $allowedKeys
    ) {
    }

    public function get(string $key, mixed $default = null): mixed
    {
        if (!in_array($key, $this->allowedKeys, true)) {
            return $default;
        }

        $this->load();

        if (array_key_exists($key, $this->values)) {
            return $this->values[$key];
        }

        return $default;
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        $this->load();
        $result = [];
        foreach ($this->allowedKeys as $key) {
            if (array_key_exists($key, $this->values)) {
                $result[$key] = $this->values[$key];
            }
        }

        return $result;
    }

    public function source(string $key): string
    {
        $this->load();
        return $this->sources[$key] ?? 'CONFIG';
    }

    private function load(): void
    {
        if ($this->loaded) {
            return;
        }

        $this->loaded = true;
        foreach ($this->allowedKeys as $key) {
            if (array_key_exists($key, $this->defaults)) {
                $this->values[$key] = $this->defaults[$key];
                $this->sources[$key] = 'CONFIG';
            }
        }

        if ($this->dbFailed) {
            return;
        }

        try {
            if (!$this->db->healthCheck()) {
                return;
            }

            $pdo = $this->db->pdo();
            $rows = $this->fetchSettings($pdo);
            foreach ($rows as $row) {
                $key = (string) ($row['key'] ?? '');
                if (!in_array($key, $this->allowedKeys, true)) {
                    continue;
                }
                $type = (string) ($row['type'] ?? 'string');
                $value = $this->deserialize((string) ($row['value'] ?? ''), $type);
                $this->values[$key] = $value;
                $this->sources[$key] = 'DB';
            }
        } catch (Throwable) {
            $this->dbFailed = true;
        }
    }

    /** @return array<int, array{key: string, value: string, type: string}> */
    private function fetchSettings(PDO $pdo): array
    {
        if ($this->allowedKeys === []) {
            return [];
        }

        $placeholders = implode(', ', array_fill(0, count($this->allowedKeys), '?'));
        $sql = "SELECT `key`, `value`, `type` FROM settings WHERE `key` IN ({$placeholders})";
        $stmt = $pdo->prepare($sql);
        $stmt->execute(array_values($this->allowedKeys));
        $rows = $stmt->fetchAll();
        return is_array($rows) ? $rows : [];
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
