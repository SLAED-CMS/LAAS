<?php
declare(strict_types=1);

namespace Laas\Database\Migrations;

use Laas\Database\DbSqlFingerprint;

final class MigrationSafetyAnalyzer
{
    /** @return array<int, array{type: string, message_key: string}> */
    private function patterns(): array
    {
        return [
            ['type' => 'drop_table', 'message_key' => 'db.migrations.blocked', 'pattern' => '/\bDROP\s+TABLE\b/i'],
            ['type' => 'drop_column', 'message_key' => 'db.migrations.blocked', 'pattern' => '/\bDROP\s+COLUMN\b/i'],
            ['type' => 'truncate', 'message_key' => 'db.migrations.blocked', 'pattern' => '/\bTRUNCATE\b/i'],
            ['type' => 'alter_column_type', 'message_key' => 'db.migrations.blocked', 'pattern' => '/\bALTER\s+TABLE\b[^;]*(\bALTER\s+COLUMN\b|\bMODIFY\b|\bCHANGE\b)/i'],
        ];
    }

    /** @return array<int, array{type: string, migration: string, fingerprint: string|null, message_key: string}> */
    public function analyzeMigration(string $migrationName, string $path): array
    {
        if (!is_file($path)) {
            return [];
        }

        $content = (string) file_get_contents($path);
        $segment = $this->extractUpSection($content);
        if ($segment === '') {
            $segment = $content;
        }

        $issues = [];
        foreach ($this->patterns() as $pattern) {
            $regex = (string) ($pattern['pattern'] ?? '');
            if ($regex === '') {
                continue;
            }
            if (!preg_match_all($regex, $segment, $matches, PREG_OFFSET_CAPTURE)) {
                continue;
            }
            foreach ($matches[0] as $match) {
                $offset = (int) ($match[1] ?? 0);
                $snippet = $this->extractStatement($segment, $offset);
                $fingerprint = $snippet !== '' ? DbSqlFingerprint::fingerprint($snippet) : '';
                $issues[] = [
                    'type' => (string) ($pattern['type'] ?? 'unsafe'),
                    'migration' => $migrationName,
                    'fingerprint' => $fingerprint !== '' ? $fingerprint : null,
                    'message_key' => (string) ($pattern['message_key'] ?? 'db.migrations.blocked'),
                ];
            }
        }

        return $issues;
    }

    /**
     * @param array<string, string> $migrations
     * @return array<int, array{type: string, migration: string, fingerprint: string|null, message_key: string}>
     */
    public function analyzeAll(array $migrations): array
    {
        $issues = [];
        foreach ($migrations as $name => $path) {
            $issues = array_merge($issues, $this->analyzeMigration((string) $name, (string) $path));
        }

        return $issues;
    }

    private function extractUpSection(string $content): string
    {
        $start = stripos($content, 'function up');
        if ($start === false) {
            return '';
        }
        $end = stripos($content, 'function down');
        if ($end === false || $end <= $start) {
            return substr($content, $start);
        }

        return substr($content, $start, $end - $start);
    }

    private function extractStatement(string $content, int $offset): string
    {
        $before = substr($content, 0, $offset);
        $start = max(
            strrpos($before, "\n") ?: 0,
            strrpos($before, ';') ?: 0
        );
        $start = $start > 0 ? $start + 1 : 0;

        $end = strpos($content, ';', $offset);
        if ($end === false) {
            $end = strpos($content, "\n", $offset);
        }
        if ($end === false) {
            $end = strlen($content);
        }

        return trim(substr($content, $start, $end - $start));
    }
}
