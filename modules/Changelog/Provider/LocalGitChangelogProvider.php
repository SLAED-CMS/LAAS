<?php

declare(strict_types=1);

namespace Laas\Modules\Changelog\Provider;

use Laas\Modules\Changelog\Dto\ChangelogCommit;
use Laas\Modules\Changelog\Dto\ChangelogPage;
use Laas\Modules\Changelog\Dto\ProviderTestResult;
use RuntimeException;

final class LocalGitChangelogProvider implements ChangelogProviderInterface
{
    private string $delimiterRecord = "\x1e";
    private string $delimiterField = "\x1f";

    public function __construct(
        private string $repoPath,
        private string $gitBinary = 'git'
    ) {
    }

    public function fetchCommits(string $branch, int $limit, int $page, bool $includeMerges, array $filters = []): ChangelogPage
    {
        $limit = max(1, $limit);
        $page = max(1, $page);
        $skip = ($page - 1) * $limit;
        $wanted = $limit + 1;

        $command = $this->buildCommand($branch, $wanted, $skip, $includeMerges, $filters);
        $output = $this->runCommand($command);

        $commits = $this->parseOutput($output);
        $hasMore = count($commits) > $limit;
        if ($hasMore) {
            $commits = array_slice($commits, 0, $limit);
        }

        return new ChangelogPage($commits, $page, $limit, $hasMore);
    }

    public function testConnection(): ProviderTestResult
    {
        try {
            $command = [
                $this->gitBinary,
                '-C',
                $this->repoPath,
                'rev-parse',
                '--is-inside-work-tree',
            ];
            $out = $this->runCommand($command);
            $trimmed = trim($out);
            if ($trimmed === 'true') {
                return new ProviderTestResult(true, 'OK');
            }
        } catch (RuntimeException $e) {
            return new ProviderTestResult(false, $e->getMessage());
        }

        return new ProviderTestResult(false, 'Git repository not available');
    }

    /** @return array<int, string> */
    /** @param array<string, string> $filters */
    public function buildCommand(string $branch, int $limit, int $skip, bool $includeMerges, array $filters): array
    {
        $format = '%H%x1f%h%x1f%an%x1f%ae%x1f%ad%x1f%s%x1f%b%x1e';
        $parts = [
            $this->gitBinary,
            '-C',
            $this->repoPath,
            'log',
            $branch,
            '--no-color',
            '--date=iso-strict',
            '--pretty=format:' . $format,
            '-n',
            (string) $limit,
            '--skip',
            (string) $skip,
        ];
        $author = trim((string) ($filters['author'] ?? ''));
        $search = trim((string) ($filters['search'] ?? ''));
        $dateFrom = trim((string) ($filters['date_from'] ?? ''));
        $dateTo = trim((string) ($filters['date_to'] ?? ''));
        $file = trim((string) ($filters['file'] ?? ''));
        if ($author !== '') {
            $parts[] = '--author=' . $author;
        }
        if ($search !== '') {
            $parts[] = '--fixed-strings';
            $parts[] = '--grep=' . $search;
        }
        if ($dateFrom !== '') {
            $parts[] = '--since=' . $dateFrom;
        }
        if ($dateTo !== '') {
            $parts[] = '--until=' . $dateTo;
        }
        if (!$includeMerges) {
            $parts[] = '--no-merges';
        }
        if ($file !== '') {
            $parts[] = '--';
            $parts[] = $file;
        }

        return $parts;
    }

    private function runCommand(array|string $command): string
    {
        $commandLabel = is_array($command) ? $this->formatCommandForLog($command) : $command;
        error_log("[LocalGit] runCommand() - executing: {$commandLabel}");

        $descriptors = [
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $proc = proc_open($command, $descriptors, $pipes);
        if (!is_resource($proc)) {
            error_log('[LocalGit] runCommand() - FAILED: proc_open failed');
            throw new RuntimeException('git exec failed: could not start process');
        }

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($proc);

        error_log("[LocalGit] runCommand() - exit code: {$exit}");
        if ($stderr !== false && $stderr !== '') {
            error_log("[LocalGit] runCommand() - stderr: {$stderr}");
        }

        if ($exit !== 0) {
            $error = 'git command failed (exit: ' . $exit . ')';
            if ($stderr !== false && $stderr !== '') {
                $error .= ': ' . trim($stderr);
            }
            error_log("[LocalGit] runCommand() - FAILED: {$error}");
            throw new RuntimeException($error);
        }

        $stdoutStr = is_string($stdout) ? $stdout : '';
        error_log('[LocalGit] runCommand() - SUCCESS, output length: ' . strlen($stdoutStr));
        return $stdoutStr;
    }

    /** @param array<int, string> $command */
    private function formatCommandForLog(array $command): string
    {
        $parts = [];
        foreach ($command as $part) {
            if ($part === '') {
                $parts[] = '""';
                continue;
            }
            if (strpbrk($part, " \t\"") === false) {
                $parts[] = $part;
                continue;
            }
            $parts[] = '"' . str_replace('"', '\"', $part) . '"';
        }
        return implode(' ', $parts);
    }

    /** @return array<int, ChangelogCommit> */
    private function parseOutput(string $output): array
    {
        $commits = [];
        $records = explode($this->delimiterRecord, $output);
        foreach ($records as $record) {
            $record = trim($record);
            if ($record === '') {
                continue;
            }
            $fields = explode($this->delimiterField, $record);
            if (count($fields) < 7) {
                continue;
            }
            [$sha, $short, $author, $email, $date, $title, $body] = $fields;
            $commits[] = new ChangelogCommit(
                $sha,
                $short,
                $title,
                trim($body),
                $author,
                $email !== '' ? $email : null,
                $date,
                null
            );
        }

        return $commits;
    }
}
