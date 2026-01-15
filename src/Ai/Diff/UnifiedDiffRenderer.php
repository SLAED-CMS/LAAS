<?php
declare(strict_types=1);

namespace Laas\Ai\Diff;

final class UnifiedDiffRenderer
{
    private const MAX_LINES = 400;

    /**
     * @param array<int, array<string, mixed>> $fileChanges
     * @return array<int, array{path: string, op: string, diff: string, stats: array{added: int, removed: int}}>
     */
    public function render(array $fileChanges): array
    {
        $out = [];
        foreach ($fileChanges as $item) {
            if (!is_array($item)) {
                continue;
            }

            $path = (string) ($item['path'] ?? '');
            $op = (string) ($item['op'] ?? '');
            if ($path === '' || $op === '') {
                continue;
            }

            $before = is_string($item['before'] ?? null) ? (string) $item['before'] : null;
            $after = is_string($item['after'] ?? null) ? (string) $item['after'] : null;
            $content = is_string($item['content'] ?? null) ? (string) $item['content'] : null;

            [$diffLines, $stats] = $this->buildDiff($op, $before, $after, $content);
            $out[] = [
                'path' => $path,
                'op' => $op,
                'diff' => implode("\n", $diffLines),
                'stats' => $stats,
            ];
        }

        return $out;
    }

    /**
     * @return array{0: array<int, string>, 1: array{added: int, removed: int}}
     */
    private function buildDiff(string $op, ?string $before, ?string $after, ?string $content): array
    {
        $lines = ['@@'];
        $added = 0;
        $removed = 0;

        if ($op === 'create') {
            $createLines = $this->splitLines($content ?? '');
            if ($createLines === []) {
                $createLines = ['(empty)'];
            }
            foreach ($createLines as $line) {
                $lines[] = '+' . $line;
                $added++;
            }
        } elseif ($op === 'delete') {
            $deleteLines = $this->splitLines($content ?? '');
            if ($deleteLines === []) {
                $deleteLines = ['(deleted)'];
            }
            foreach ($deleteLines as $line) {
                $lines[] = '-' . $line;
                $removed++;
            }
        } else {
            if ($before !== null || $after !== null) {
                $beforeLines = $before !== null ? $this->splitLines($before) : [];
                $afterLines = $after !== null ? $this->splitLines($after) : [];
                if ($beforeLines === [] && $before !== null) {
                    $beforeLines = ['(empty)'];
                }
                if ($afterLines === [] && $after !== null) {
                    $afterLines = ['(empty)'];
                }
                foreach ($beforeLines as $line) {
                    $lines[] = '-' . $line;
                    $removed++;
                }
                foreach ($afterLines as $line) {
                    $lines[] = '+' . $line;
                    $added++;
                }
            } else {
                $afterLines = $this->splitLines($content ?? '');
                if ($afterLines === []) {
                    $afterLines = ['(empty)'];
                }
                $lines[] = '-(unknown)';
                $removed++;
                foreach ($afterLines as $line) {
                    $lines[] = '+' . $line;
                    $added++;
                }
            }
        }

        $lines = $this->truncate($lines);

        return [$lines, ['added' => $added, 'removed' => $removed]];
    }

    /**
     * @return array<int, string>
     */
    private function splitLines(string $content): array
    {
        $normalized = str_replace(["\r\n", "\r"], "\n", $content);
        if ($normalized === '') {
            return [];
        }
        return explode("\n", $normalized);
    }

    /**
     * @param array<int, string> $lines
     * @return array<int, string>
     */
    private function truncate(array $lines): array
    {
        if (count($lines) <= self::MAX_LINES) {
            return $lines;
        }

        $lines = array_slice($lines, 0, self::MAX_LINES);
        $lines[] = '... (truncated)';
        return $lines;
    }
}
