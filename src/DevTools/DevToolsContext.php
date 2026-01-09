<?php
declare(strict_types=1);

namespace Laas\DevTools;

final class DevToolsContext
{
    private string $requestId;
    private float $startedAt;
    private float $durationMs = 0.0;
    private float $dbTotalMs = 0.0;
    private int $dbCount = 0;
    private int $memoryPeak = 0;
    private array $dbQueries = [];
    private array $dbDuplicates = [];
    private array $externalCalls = [];
    private array $cacheStats = [
        'hits' => 0,
        'misses' => 0,
        'sets' => 0,
    ];
    private bool $externalAvailable = false;
    private bool $cacheAvailable = false;
    private array $request = [];
    private array $user = [];
    private array $flags = [];
    private array $response = [];
    private array $media = [];
    private array $jsErrors = [];

    public function __construct(array $flags)
    {
        $requestId = $flags['request_id'] ?? null;
        $this->requestId = is_string($requestId) && $requestId !== '' ? $requestId : bin2hex(random_bytes(8));
        $this->startedAt = microtime(true);
        $this->flags = $flags;
        $enabled = (bool) ($flags['enabled'] ?? false);
        $this->externalAvailable = $enabled;
        $this->cacheAvailable = $enabled;
        $this->request = [
            'method' => '',
            'path' => '',
            'get' => [],
            'get_raw' => '',
            'post' => [],
            'post_raw' => '',
            'cookies' => [],
            'headers' => [],
        ];
        $this->user = [
            'id' => null,
            'username' => null,
            'roles' => [],
        ];
        $this->response = [
            'status' => 0,
            'content_type' => '',
        ];
        $this->media = [];
    }

    public function getRequestId(): string
    {
        return $this->requestId;
    }

    public function getFlag(string $key, mixed $default = null): mixed
    {
        return $this->flags[$key] ?? $default;
    }

    public function getStartedAt(): float
    {
        return $this->startedAt;
    }

    public function setRequest(array $data): void
    {
        $this->request = $data;
    }

    public function setUser(array $data): void
    {
        $this->user = $data;
    }

    public function setJsErrors(array $errors): void
    {
        $this->jsErrors = $errors;
    }

    public function getUser(): array
    {
        return $this->user;
    }

    public function hasRole(string $role): bool
    {
        $roles = $this->user['roles'] ?? [];
        if (!is_array($roles)) {
            return false;
        }
        return in_array($role, $roles, true);
    }

    public function setResponse(array $data): void
    {
        $this->response = $data;
    }

    public function setMedia(array $data): void
    {
        $this->media = $data;
    }

    public function addExternalCall(string $method, string $url, int $status, float $durationMs): void
    {
        if (!($this->flags['enabled'] ?? false)) {
            return;
        }
        if (count($this->externalCalls) >= 50) {
            return;
        }
        $parts = parse_url($url);
        $host = (string) ($parts['host'] ?? '');
        $path = (string) ($parts['path'] ?? '');
        $this->externalCalls[] = [
            'method' => strtoupper($method),
            'host' => $host,
            'path' => $path !== '' ? $path : '/',
            'status' => $status,
            'total_ms' => round($durationMs, 2),
        ];
        $this->externalAvailable = true;
    }

    public function recordCacheGet(bool $hit): void
    {
        if (!($this->flags['enabled'] ?? false)) {
            return;
        }
        $this->cacheAvailable = true;
        if ($hit) {
            $this->cacheStats['hits']++;
        } else {
            $this->cacheStats['misses']++;
        }
    }

    public function recordCacheSet(): void
    {
        if (!($this->flags['enabled'] ?? false)) {
            return;
        }
        $this->cacheAvailable = true;
        $this->cacheStats['sets']++;
    }

    public function addDbQuery(string $sql, int $paramsCount, float $durationMs): void
    {
        $this->trackDuplicate($sql, $paramsCount, $durationMs);

        if ($this->dbCount >= 50) {
            return;
        }

        $index = $this->dbCount + 1;
        $this->dbQueries[] = [
            'index' => $index,
            'sql' => $this->normalizeSql($sql),
            'params' => $paramsCount,
            'duration_ms' => $durationMs,
        ];
        $this->dbCount++;
        $this->dbTotalMs += $durationMs;
    }

    public function finalize(): void
    {
        $this->durationMs = (microtime(true) - $this->startedAt) * 1000;
        $this->memoryPeak = (int) memory_get_peak_usage(true);
    }

    public function toArray(): array
    {
        $memoryMb = $this->memoryPeak > 0 ? ($this->memoryPeak / 1048576) : 0.0;
        $topSlow = $this->dbQueries;
        usort($topSlow, static function (array $a, array $b): int {
            return ($b['duration_ms'] ?? 0) <=> ($a['duration_ms'] ?? 0);
        });
        $topSlow = array_slice($topSlow, 0, 5);
        $grouped = array_values($this->dbDuplicates);
        usort($grouped, static function (array $a, array $b): int {
            return ($b['count'] ?? 0) <=> ($a['count'] ?? 0);
        });
        $duplicates = array_values(array_filter($this->dbDuplicates, static function (array $row): bool {
            return (int) ($row['count'] ?? 0) > 1;
        }));
        usort($duplicates, static function (array $a, array $b): int {
            return ($b['count'] ?? 0) <=> ($a['count'] ?? 0);
        });
        $duplicateCount = 0;
        foreach ($this->dbDuplicates as $row) {
            if ((int) ($row['count'] ?? 0) > 1) {
                $duplicateCount++;
            }
        }
        $external = $this->buildExternalStats();
        $cache = $this->buildCacheStats();

        return [
            'request_id' => $this->requestId,
            'duration_ms' => round($this->durationMs, 2),
            'memory_mb' => round($memoryMb, 2),
            'db' => [
                'count' => $this->dbCount,
                'unique' => count($this->dbDuplicates),
                'duplicate_count' => $duplicateCount,
                'total_ms' => round($this->dbTotalMs, 2),
                'queries' => $this->dbQueries,
                'top_slow' => $topSlow,
                'grouped' => $grouped,
                'duplicates' => $duplicates,
            ],
            'external' => $external,
            'cache' => $cache,
            'profile' => $this->buildProfile($duplicateCount, $grouped, $duplicates, $memoryMb),
            'request' => $this->request,
            'user' => $this->user,
            'flags' => $this->flags,
            'response' => $this->response,
            'media' => $this->media,
            'js_errors' => $this->jsErrors,
        ];
    }

    private function normalizeSql(string $sql): string
    {
        return trim($sql);
    }

    private function trackDuplicate(string $sql, int $paramsCount, float $durationMs): void
    {
        $fingerprint = $this->fingerprint($sql, $paramsCount);
        if (!isset($this->dbDuplicates[$fingerprint])) {
            $this->dbDuplicates[$fingerprint] = [
                'fingerprint' => $fingerprint,
                'hash' => sha1($fingerprint),
                'sql' => $this->normalizeFingerprintSql($sql),
                'params' => $paramsCount,
                'count' => 0,
                'total_ms' => 0.0,
                'avg_ms' => 0.0,
                'samples' => [],
                'trace' => [],
            ];
        }

        $entry = $this->dbDuplicates[$fingerprint];
        $entry['count']++;
        $entry['total_ms'] += $durationMs;
        $entry['avg_ms'] = $entry['count'] > 0 ? round($entry['total_ms'] / $entry['count'], 2) : 0.0;

        if (count($entry['samples']) < 3) {
            $entry['samples'][] = $this->normalizeSql($sql);
        }

        $isDev = (bool) ($this->flags['is_dev'] ?? (strtolower((string) ($this->flags['env'] ?? '')) !== 'prod'));
        if ($isDev && $entry['count'] === 2 && $entry['trace'] === []) {
            $entry['trace'] = $this->buildTrace();
        }

        $this->dbDuplicates[$fingerprint] = $entry;
    }

    private function fingerprint(string $sql, int $paramsCount): string
    {
        $normalized = $this->normalizeFingerprintSql($sql);
        return $normalized . '|params:' . $paramsCount;
    }

    private function normalizeFingerprintSql(string $sql): string
    {
        return preg_replace('/\s+/', ' ', trim($sql)) ?? trim($sql);
    }

    private function buildProfile(int $duplicateCount, array $grouped, array $duplicates, float $memoryMb): array
    {
        $budgets = $this->normalizeBudgets($this->flags['budgets'] ?? []);
        $totalMs = round($this->durationMs, 2);
        $sqlTotalMs = round($this->dbTotalMs, 2);
        $rawCount = $this->dbCount;
        $uniqueCount = count($this->dbDuplicates);

        $topSlow = $this->topSlowest($grouped, 3, $budgets['slow_sql_warn']);
        $topDuplicates = $this->topDuplicates($duplicates, 3);
        $slowSqlMax = $topSlow !== [] ? (float) ($topSlow[0]['total_ms'] ?? 0) : 0.0;
        $duplicateCalls = 0;
        foreach ($duplicates as $row) {
            $duplicateCalls += (int) ($row['count'] ?? 0);
        }
        $external = $this->buildExternalStats();
        $cache = $this->buildCacheStats();
        $slowHttpMax = $external['top3_slowest_calls'] !== []
            ? (float) ($external['top3_slowest_calls'][0]['total_ms'] ?? 0)
            : 0.0;

        $budgetTotal = BudgetClassifier::classify($totalMs, $budgets['total_time_warn'], $budgets['total_time_bad']);
        $budgetSlowSql = BudgetClassifier::classify($slowSqlMax, $budgets['slow_sql_warn'], $budgets['slow_sql_bad']);
        $budgetDuplicates = $duplicateCount > 0 ? BudgetClassifier::warn() : BudgetClassifier::ok();
        $budgetSlowHttp = $external['available']
            ? BudgetClassifier::classify($slowHttpMax, $budgets['slow_http_warn'], $budgets['slow_http_bad'])
            : BudgetClassifier::na();

        $warnings = [];
        if ($budgetTotal['status'] !== 'ok') {
            $warnings[] = 'high_total_time';
        }
        if ($budgetSlowSql['status'] !== 'ok') {
            $warnings[] = 'slow_sql';
        }
        if ($budgetSlowHttp['status'] !== 'ok' && $budgetSlowHttp['status'] !== 'na') {
            $warnings[] = 'slow_external_http';
        }
        if ($duplicateCount > 0) {
            $warnings[] = 'duplicate_sql';
        }

        $errors = [];
        $status = (int) ($this->response['status'] ?? 0);
        if ($status >= 500) {
            $errors[] = [
                'type' => 'http',
                'message' => 'HTTP ' . $status,
            ];
        }

        $issues = [];
        if (in_array('slow_sql', $warnings, true)) {
            $issues[] = [
                'key' => 'slow_sql',
                'is_errors' => false,
                'is_external' => false,
                'title_key' => 'devtools.issue.slow_sql',
                'action_key' => 'devtools.open_sql',
                'reason' => 'max ' . $slowSqlMax . ' ms',
                'items' => $topSlow,
                'href' => '#devtools-sql-grouped',
            ];
        }
        if (in_array('duplicate_sql', $warnings, true)) {
            $issues[] = [
                'key' => 'duplicate_sql',
                'is_errors' => false,
                'is_external' => false,
                'title_key' => 'devtools.issue.duplicate_sql',
                'action_key' => 'devtools.open_duplicates',
                'reason' => 'count ' . $duplicateCount,
                'items' => $topDuplicates,
                'href' => '#devtools-sql-duplicates',
            ];
        }
        if (in_array('slow_external_http', $warnings, true)) {
            $issues[] = [
                'key' => 'slow_external_http',
                'is_errors' => false,
                'is_external' => true,
                'title_key' => 'devtools.issue.slow_external_http',
                'action_key' => 'devtools.open_external',
                'reason' => 'max ' . $slowHttpMax . ' ms',
                'items' => $external['top3_slowest_calls'] ?? [],
                'href' => '#devtools-external',
            ];
        }
        if ($errors !== []) {
            $issues[] = [
                'key' => 'errors',
                'is_errors' => true,
                'is_external' => false,
                'title_key' => 'devtools.issue.errors',
                'action_key' => 'devtools.open_request',
                'reason' => 'count ' . count($errors),
                'items' => $errors,
                'href' => '#devtools-request',
            ];
        }
        if (in_array('high_total_time', $warnings, true)) {
            $issues[] = [
                'key' => 'high_total_time',
                'is_errors' => false,
                'is_external' => false,
                'title_key' => 'devtools.issue.high_total_time',
                'action_key' => 'devtools.open_overview',
                'reason' => 'total ' . $totalMs . ' ms',
                'items' => [],
                'href' => '#devtools-overview',
            ];
        }

        $issuesCompact = [];
        $firstIssueLabel = 'None';
        $firstIssueHref = '#devtools-overview';
        foreach ($issues as $issue) {
            $key = (string) ($issue['key'] ?? '');
            $label = match ($key) {
                'duplicate_sql' => 'Duplicate SQL',
                'slow_external_http' => 'Slow external',
                'high_total_time' => 'High total time',
                'errors' => 'Errors',
                'slow_sql' => 'Slow SQL',
                default => 'Issue',
            };
            $actionLabel = match ($key) {
                'duplicate_sql' => 'Open duplicates',
                'slow_external_http' => 'Open external',
                'high_total_time' => 'Open overview',
                'errors' => 'Open request',
                'slow_sql' => 'Open SQL',
                default => 'Open',
            };
            $issuesCompact[] = [
                'label' => $label,
                'value' => (string) ($issue['reason'] ?? ''),
                'href' => (string) ($issue['href'] ?? '#devtools-overview'),
                'action_label' => $actionLabel,
            ];
            if ($firstIssueLabel === 'None') {
                $firstIssueLabel = $label;
                $firstIssueHref = (string) ($issue['href'] ?? '#devtools-overview');
            }
        }

        $segments = $this->buildSegments($totalMs, $sqlTotalMs, (float) ($external['total_ms'] ?? 0));
        $compact = $this->buildCompactView(
            $topSlow,
            $topDuplicates,
            $duplicates,
            $grouped,
            $external,
            $budgetTotal,
            $budgets,
            $totalMs,
            $sqlTotalMs,
            $rawCount,
            $uniqueCount,
            $duplicateCount,
            $duplicateCalls,
            $cache,
            $memoryMb
        );
        $terminal = $this->buildTerminalView(
            $totalMs,
            $memoryMb,
            $rawCount,
            $uniqueCount,
            $duplicateCount,
            $sqlTotalMs,
            $duplicates,
            $topDuplicates,
            $topSlow,
            $external,
            $cache,
            $segments,
            $warnings,
            $errors,
            $budgets
        );

        return [
            'request' => [
                'id' => $this->requestId,
                'method' => (string) ($this->request['method'] ?? ''),
                'path' => (string) ($this->request['path'] ?? ''),
                'route' => $this->request['route'] ?? null,
                'controller' => $this->request['controller'] ?? null,
                'action' => $this->request['action'] ?? null,
                'user_id' => $this->user['id'] ?? null,
                'roles' => $this->user['roles'] ?? [],
            ],
            'total_duration_ms' => $totalMs,
            'segments' => $segments,
            'sql' => [
                'raw_count' => $rawCount,
                'unique_count' => $uniqueCount,
                'duplicates_count' => $duplicateCount,
                'duplicate_calls' => $duplicateCalls,
                'slow_count' => count($topSlow),
                'slow_threshold' => $budgets['slow_sql_warn'],
                'top3_slowest_queries' => $topSlow,
                'top3_duplicate_queries' => $topDuplicates,
            ],
            'external' => $external,
            'cache' => $cache,
            'warnings' => $warnings,
            'errors' => $errors,
            'errors_count' => count($errors),
            'issues' => $issues,
            'issues_compact' => $issuesCompact,
            'terminal' => $terminal,
            'bottleneck' => [
                'label' => $firstIssueLabel,
                'href' => $firstIssueHref,
            ],
            'compact' => $compact,
            'budget' => [
                'total_time' => $budgetTotal,
                'slow_sql' => $budgetSlowSql,
                'slow_http' => $budgetSlowHttp,
                'duplicates' => $budgetDuplicates,
            ],
        ];
    }

    private function buildTerminalView(
        float $totalMs,
        float $memoryMb,
        int $rawCount,
        int $uniqueCount,
        int $duplicateCount,
        float $sqlTotalMs,
        array $duplicates,
        array $topDuplicates,
        array $topSlow,
        array $external,
        array $cache,
        array $segments,
        array $warnings,
        array $errors,
        array $budgets
    ): array {
        $status = (int) ($this->response['status'] ?? 0);
        $method = (string) ($this->request['method'] ?? '');
        $path = (string) ($this->request['path'] ?? '');
        $methodUpper = $method !== '' ? strtoupper($method) : '';
        $httpCount = (int) ($external['count'] ?? 0);
        $httpMaxMs = 0.0;
        if (!empty($external['top3_slowest_calls'])) {
            $httpMaxMs = (float) ($external['top3_slowest_calls'][0]['total_ms'] ?? 0);
        }
        $cacheRate = $cache['available'] ? sprintf('%.1f%%', (float) ($cache['hit_rate'] ?? 0)) : 'n/a';

        $postParams = [];
        if ($methodUpper === 'POST' && !empty($this->request['post'])) {
            foreach ($this->request['post'] as $key => $value) {
                if (is_string($value) || is_numeric($value)) {
                    $postParams[] = $key . ' = ' . $value;
                }
                if (count($postParams) >= 3) {
                    break;
                }
            }
        }

        $promptData = [
            'sql_raw' => $rawCount,
            'sql_unique' => $uniqueCount,
            'sql_dup' => $duplicateCount,
            'sql_ms' => round($sqlTotalMs, 1),
            'total_ms' => round($totalMs, 2),
            'memory_mb' => round($memoryMb, 0),
            'cache_rate' => $cacheRate,
            'http_count' => $httpCount,
            'http_max_ms' => round($httpMaxMs, 1),
            'method' => $methodUpper,
            'path' => $path,
            'post_params' => $postParams,
            'status' => $status,
            'request_id' => $this->requestId,
        ];

        $promptLine = TerminalFormatter::formatPromptLine(
            $methodUpper,
            $path,
            $status,
            $totalMs,
            $memoryMb,
            $this->requestId
        );
        $summarySegments = [];
        $sqlText = sprintf('%d/%d d%d %.1fms', $rawCount, $uniqueCount, $duplicateCount, $sqlTotalMs);
        $sqlHref = $duplicateCount > 0 ? '#devtools-sql-duplicates' : '#devtools-sql-grouped';
        $summarySegments[] = [
            'label' => 'SQL',
            'value' => $sqlText,
            'href' => $sqlHref,
            'available' => true,
            'sep' => '  ',
        ];

        $cacheText = $cache['available']
            ? sprintf('%.1f%%', (float) ($cache['hit_rate'] ?? 0))
            : 'n/a';
        $summarySegments[] = [
            'label' => 'CACHE',
            'value' => $cacheText,
            'href' => '#devtools-overview',
            'available' => (bool) ($cache['available'] ?? false),
            'sep' => '  ',
        ];

        $httpMax = 0.0;
        if (!empty($external['top3_slowest_calls'])) {
            $httpMax = (float) ($external['top3_slowest_calls'][0]['total_ms'] ?? 0);
        }
        $httpText = $external['available']
            ? sprintf('%d (%.1fms)', (int) ($external['count'] ?? 0), $httpMax)
            : 'n/a';
        $summarySegments[] = [
            'label' => 'HTTP',
            'value' => $httpText,
            'href' => '#devtools-external',
            'available' => (bool) ($external['available'] ?? false),
            'sep' => '',
        ];

        $summaryParts = [];
        foreach ($summarySegments as $segment) {
            $summaryParts[] = TerminalFormatter::formatSummarySegment(
                (string) ($segment['label'] ?? ''),
                (string) ($segment['value'] ?? '')
            );
        }
        $summaryLine = implode('  ', $summaryParts);

        $warningTokens = [];
        $slowHttpWarn = (float) ($budgets['slow_http_warn'] ?? 200);
        $slowSqlWarn = (float) ($budgets['slow_sql_warn'] ?? 50);
        if (in_array('slow_external_http', $warnings, true) && $external['available'] && $httpMax >= $slowHttpWarn) {
            $slowHost = (string) ($external['top3_slowest_calls'][0]['host'] ?? '');
            $slowPath = (string) ($external['top3_slowest_calls'][0]['path'] ?? '');
            $slowLabel = $slowHost !== '' ? $slowHost . $slowPath : $slowPath;
            $warningTokens[] = sprintf('Slow HTTP request - %s %.1fms', $slowLabel !== '' ? $slowLabel : 'n/a', $httpMax);
        }
        if (in_array('duplicate_sql', $warnings, true) && $duplicateCount > 0) {
            $warningTokens[] = sprintf('SQL query duplicates - %d', $duplicateCount);
        }
        if (in_array('slow_sql', $warnings, true) && count($topSlow) > 0) {
            $warningTokens[] = sprintf('Slow SQL queries - %d', count($topSlow));
        }
        if ($errors !== []) {
            $warningTokens[] = sprintf('Errors - %d', count($errors));
        }
        if (in_array('high_total_time', $warnings, true)) {
            $warningTokens[] = sprintf('High total time - %.1fms', $totalMs);
        }

        $warningsLine = TerminalFormatter::formatWarningsLine($warningTokens);
        $warningTokensDecorated = [];
        foreach ($warningTokens as $token) {
            $warningTokensDecorated[] = [
                'text' => $token,
            ];
        }

        $offenders = [];
        if ($external['available'] && !empty($external['top3_slowest_calls'])) {
            foreach ($external['top3_slowest_calls'] as $call) {
                $total = (float) ($call['total_ms'] ?? 0);
                if ($total < $slowHttpWarn) {
                    continue;
                }
                $method = (string) ($call['method'] ?? '');
                $host = (string) ($call['host'] ?? '');
                $path = (string) ($call['path'] ?? '');
                $detail = trim($method . ' ' . $host . $path);
                $detailUrl = trim($host . $path);
                $detailStatus = (int) ($call['status'] ?? 0);
                $value = sprintf('%.1fms', $total);
                $detailMethodUpper = $method !== '' ? strtoupper($method) : '';
                $line = TerminalFormatter::formatOffenderLine('!', 'HTTP', $detail, $value);
                $offenders[] = [
                    'id' => '',
                    'line' => $line,
                    'type' => 'HTTP',
                    'marker' => '!',
                    'detail_text' => $detail,
                    'detail_method' => $detailMethodUpper,
                    'detail_url' => $detailUrl,
                    'value' => $value,
                    'is_http' => true,
                    'is_sqld' => false,
                    'is_sql' => false,
                    'is_sqlish' => false,
                    'has_details' => true,
                    'detail' => [
                        'method' => $detailMethodUpper,
                        'url' => $detailUrl,
                        'status' => (string) ($call['status'] ?? ''),
                        'total_ms' => $total,
                    ],
                ];
            }
        }

        foreach ($duplicates as $row) {
            $fingerprint = (string) ($row['fingerprint'] ?? '');
            $count = (int) ($row['count'] ?? 0);
            $sql = (string) ($row['sql'] ?? '');
            $sqlPreview = $this->previewSql($sql);
            $avg = (float) ($row['avg_ms'] ?? 0);
            $value = sprintf('x%d %.2fms avg', $count, $avg);
            $line = TerminalFormatter::formatOffenderLine('!', 'SQLD', $sqlPreview, $value);
            $offenders[] = [
                'id' => '',
                'line' => $line,
                'type' => 'SQLD',
                'marker' => '!',
                'detail_text' => $sqlPreview,
                'value' => $value,
                'is_http' => false,
                'is_sqld' => true,
                'is_sql' => false,
                'is_sqlish' => true,
                'has_details' => true,
                'detail' => [
                    'sql' => $sql,
                    'count' => $count,
                    'avg_ms' => $avg,
                    'trace' => $row['trace'] ?? [],
                ],
            ];
        }

        foreach ($topSlow as $row) {
            $total = (float) ($row['total_ms'] ?? 0);
            if ($total < $slowSqlWarn) {
                continue;
            }
            $sqlPreview = (string) ($row['sql_preview'] ?? '');
            $value = sprintf('%.1fms', $total);
            $line = TerminalFormatter::formatOffenderLine('!', 'SQL', $sqlPreview, $value);
            $offenders[] = [
                'id' => '',
                'line' => $line,
                'type' => 'SQL',
                'marker' => '!',
                'detail_text' => $sqlPreview,
                'value' => $value,
                'is_http' => false,
                'is_sqld' => false,
                'is_sql' => true,
                'is_sqlish' => true,
                'has_details' => true,
                'detail' => [
                    'sql' => $sqlPreview,
                    'total_ms' => $total,
                ],
            ];
        }

        $i = 1;
        foreach ($offenders as $idx => $offender) {
            $offenders[$idx]['id'] = 'devtools-term-offender-' . $i;
            $i++;
        }

        $timelineLine = '';
        if ($segments !== []) {
            $sqlSeg = $segments[0] ?? ['ms' => 0, 'percent' => 0];
            $httpSeg = $segments[1] ?? ['ms' => 0, 'percent' => 0];
            $othSeg = $segments[2] ?? ['ms' => 0, 'percent' => 0];
            $timelineLine = TerminalFormatter::formatTimelineLine(
                (float) ($sqlSeg['percent'] ?? 0),
                (float) ($httpSeg['percent'] ?? 0),
                (float) ($othSeg['percent'] ?? 0),
                (float) ($sqlSeg['ms'] ?? 0),
                (float) ($httpSeg['ms'] ?? 0),
                (float) ($othSeg['ms'] ?? 0)
            );
        }

        $dumpLines = [$promptLine, $summaryLine, $warningsLine];
        foreach ($offenders as $offender) {
            $dumpLines[] = (string) ($offender['line'] ?? '');
        }
        if ($timelineLine !== '') {
            $dumpLines[] = $timelineLine;
        }

        return [
            'prompt' => $promptData,
            'prompt_line' => $promptLine,
            'summary_segments' => $summarySegments,
            'summary_line' => $summaryLine,
            'warnings_line' => $warningsLine,
            'warning_tokens' => $warningTokensDecorated,
            'offenders' => $offenders,
            'timeline_line' => $timelineLine,
            'dump_text' => implode("\n", $dumpLines),
        ];
    }

    private function buildCompactView(
        array $topSlow,
        array $topDuplicates,
        array $duplicates,
        array $grouped,
        array $external,
        array $budgetTotal,
        array $budgets,
        float $totalMs,
        float $sqlTotalMs,
        int $rawCount,
        int $uniqueCount,
        int $duplicateCount,
        int $duplicateCalls,
        array $cache,
        float $memoryMb
    ): array {
        $dupMap = [];
        foreach ($duplicates as $row) {
            $fingerprint = (string) ($row['fingerprint'] ?? '');
            if ($fingerprint === '') {
                continue;
            }
            $dupMap[$fingerprint] = $row;
        }
        $grpMap = [];
        foreach ($grouped as $row) {
            $fingerprint = (string) ($row['fingerprint'] ?? '');
            if ($fingerprint === '') {
                continue;
            }
            $grpMap[$fingerprint] = $row;
        }

        $offenders = [];
        foreach ($topDuplicates as $row) {
            $fingerprint = (string) ($row['fingerprint'] ?? '');
            $count = (int) ($row['count'] ?? 0);
            $sqlPreview = (string) ($row['sql_preview'] ?? '');
            $full = $dupMap[$fingerprint] ?? [];
            $offenders[] = [
                'type' => 'SQLD',
                'type_order' => 2,
                'marker' => '!',
                'score' => $count,
                'value' => 'd' . $count,
                'desc' => $sqlPreview,
                'href' => '#devtools-sql-duplicates',
                'is_sqld' => true,
                'is_sql' => false,
                'is_http' => false,
                'is_oth' => false,
                'detail' => [
                    'sql' => (string) ($full['sql'] ?? $sqlPreview),
                    'count' => $count,
                    'trace' => $full['trace'] ?? [],
                ],
            ];
        }
        foreach ($topSlow as $row) {
            $fingerprint = (string) ($row['fingerprint'] ?? '');
            $total = (float) ($row['total_ms'] ?? 0);
            $sqlPreview = (string) ($row['sql_preview'] ?? '');
            $full = $grpMap[$fingerprint] ?? [];
            $offenders[] = [
                'type' => 'SQL',
                'type_order' => 3,
                'marker' => '!',
                'score' => $total,
                'value' => number_format($total, 1) . 'ms',
                'desc' => $sqlPreview,
                'href' => '#devtools-sql-grouped',
                'is_sqld' => false,
                'is_sql' => true,
                'is_http' => false,
                'is_oth' => false,
                'detail' => [
                    'sql' => (string) ($full['sql'] ?? $sqlPreview),
                    'total_ms' => $total,
                ],
            ];
        }
        if (!empty($external['top3_slowest_calls'])) {
            foreach ($external['top3_slowest_calls'] as $call) {
                $total = (float) ($call['total_ms'] ?? 0);
                $url = (string) (($call['host'] ?? '') . ($call['path'] ?? ''));
                $offenders[] = [
                    'type' => 'HTTP',
                    'type_order' => 1,
                'marker' => $total >= (float) ($budgets['slow_http_warn'] ?? 200) ? '!' : ' ',
                    'score' => $total,
                    'value' => number_format($total, 1) . 'ms',
                    'desc' => $url,
                    'href' => '#devtools-external',
                    'is_sqld' => false,
                    'is_sql' => false,
                    'is_http' => true,
                    'is_oth' => false,
                    'detail' => [
                        'method' => (string) ($call['method'] ?? ''),
                        'url' => $url !== '' ? $url : '/',
                        'status' => (string) ($call['status'] ?? ''),
                        'total_ms' => $total,
                        'headers' => '-',
                    ],
                ];
            }
        }
        if (($budgetTotal['status'] ?? 'ok') !== 'ok') {
            $offenders[] = [
                'type' => 'OTH',
                'type_order' => 4,
                'marker' => '!',
                'score' => $totalMs,
                'value' => number_format($totalMs, 1) . 'ms',
                'desc' => 'Total time',
                'href' => '#devtools-overview',
                'is_sqld' => false,
                'is_sql' => false,
                'is_http' => false,
                'is_oth' => true,
                'detail' => [
                    'total_ms' => $totalMs,
                ],
            ];
        }

        usort($offenders, static function (array $a, array $b): int {
            if ($a['marker'] !== $b['marker']) {
                return $a['marker'] === '!' ? -1 : 1;
            }
            if ($a['score'] !== $b['score']) {
                return ($b['score'] <=> $a['score']);
            }
            return ($a['type_order'] <=> $b['type_order']);
        });

        $offenders = array_slice($offenders, 0, 5);
        $lines = [];
        $i = 1;
        foreach ($offenders as $offender) {
            $line = CompactFormatter::formatOffenderLine(
                (string) ($offender['marker'] ?? ' '),
                (string) ($offender['type'] ?? ''),
                (string) ($offender['value'] ?? ''),
                (string) ($offender['desc'] ?? '')
            );
            $offender['line'] = $line;
            $offender['id'] = 'devtools-offender-' . $i;
            $lines[] = $offender;
            $i++;
        }

        $sqlCompact = sprintf('%d/%d d%d %.1fms', $rawCount, $uniqueCount, $duplicateCount, $sqlTotalMs);
        $httpCompact = $external['available'] ? sprintf('%d %.1fms', (int) ($external['count'] ?? 0), (float) ($external['total_ms'] ?? 0)) : 'n/a';
        $cacheCompact = $cache['available'] ? sprintf('%.1f%%', (float) ($cache['hit_rate'] ?? 0)) : 'n/a';
        $sqlHref = $duplicateCount > 0 ? '#devtools-sql-duplicates' : '#devtools-sql-grouped';

        return [
            'status' => [
                'total_ms' => $totalMs,
                'sql_compact' => $sqlCompact,
                'sql_available' => true,
                'sql_href' => $sqlHref,
                'http_compact' => $httpCompact,
                'http_available' => (bool) ($external['available'] ?? false),
                'http_href' => '#devtools-external',
                'cache_compact' => $cacheCompact,
                'cache_available' => (bool) ($cache['available'] ?? false),
                'cache_href' => '#devtools-overview',
                'memory_mb' => round($memoryMb, 2),
                'request_id' => $this->requestId,
            ],
            'offenders' => $lines,
        ];
    }

    private function normalizeBudgets(array $budgets): array
    {
        return [
            'total_time_warn' => (float) ($budgets['total_time_warn'] ?? 200),
            'total_time_bad' => (float) ($budgets['total_time_bad'] ?? 500),
            'slow_sql_warn' => (float) ($budgets['slow_sql_warn'] ?? 50),
            'slow_sql_bad' => (float) ($budgets['slow_sql_bad'] ?? 200),
            'slow_http_warn' => (float) ($budgets['slow_http_warn'] ?? 200),
            'slow_http_bad' => (float) ($budgets['slow_http_bad'] ?? 1000),
        ];
    }

    private function topSlowest(array $grouped, int $limit, float $warnThreshold): array
    {
        $rows = [];
        foreach ($grouped as $row) {
            $totalMs = (float) ($row['total_ms'] ?? 0);
            if ($totalMs < $warnThreshold) {
                continue;
            }
            $rows[] = [
                'fingerprint' => (string) ($row['fingerprint'] ?? ''),
                'sql_preview' => $this->previewSql((string) ($row['sql'] ?? '')),
                'total_ms' => $totalMs,
                'count' => (int) ($row['count'] ?? 0),
            ];
        }
        usort($rows, static function (array $a, array $b): int {
            return ($b['total_ms'] ?? 0) <=> ($a['total_ms'] ?? 0);
        });

        return array_slice($rows, 0, $limit);
    }

    private function topDuplicates(array $duplicates, int $limit): array
    {
        $rows = [];
        foreach ($duplicates as $row) {
            $rows[] = [
                'fingerprint' => (string) ($row['fingerprint'] ?? ''),
                'sql_preview' => $this->previewSql((string) ($row['sql'] ?? '')),
                'count' => (int) ($row['count'] ?? 0),
            ];
        }
        usort($rows, static function (array $a, array $b): int {
            return ($b['count'] ?? 0) <=> ($a['count'] ?? 0);
        });

        return array_slice($rows, 0, $limit);
    }

    private function previewSql(string $sql): string
    {
        $sql = $this->normalizeFingerprintSql($sql);
        if (strlen($sql) <= 120) {
            return $sql;
        }
        return substr($sql, 0, 117) . '...';
    }

    private function buildSegments(float $totalMs, float $sqlMs, float $externalMs): array
    {
        if ($totalMs <= 0) {
            return [];
        }
        $sql = max(0.0, min($sqlMs, $totalMs));
        $external = max(0.0, min($externalMs, $totalMs - $sql));
        $other = max(0.0, $totalMs - $sql - $external);
        return [
            [
                'key' => 'sql',
                'name' => 'SQL',
                'ms' => round($sql, 2),
                'percent' => round(($sql / $totalMs) * 100, 1),
                'is_sql' => true,
            ],
            [
                'key' => 'external',
                'name' => 'External',
                'ms' => round($external, 2),
                'percent' => round(($external / $totalMs) * 100, 1),
                'is_sql' => false,
            ],
            [
                'key' => 'other',
                'name' => 'Other',
                'ms' => round($other, 2),
                'percent' => round(($other / $totalMs) * 100, 1),
                'is_sql' => false,
            ],
        ];
    }

    private function buildExternalStats(): array
    {
        if (!$this->externalAvailable && $this->externalCalls === []) {
            return [
                'available' => false,
                'count' => null,
                'total_ms' => null,
                'top3_slowest_calls' => [],
            ];
        }
        $calls = $this->externalCalls;
        usort($calls, static function (array $a, array $b): int {
            return ($b['total_ms'] ?? 0) <=> ($a['total_ms'] ?? 0);
        });
        $top = array_slice($calls, 0, 3);
        $totalMs = 0.0;
        foreach ($this->externalCalls as $call) {
            $totalMs += (float) ($call['total_ms'] ?? 0);
        }

        return [
            'available' => true,
            'count' => count($this->externalCalls),
            'total_ms' => round($totalMs, 2),
            'top3_slowest_calls' => $top,
        ];
    }

    private function buildCacheStats(): array
    {
        if (!$this->cacheAvailable) {
            return [
                'available' => false,
                'hits' => null,
                'misses' => null,
                'sets' => null,
                'hit_rate' => null,
            ];
        }
        $hits = $this->cacheStats['hits'] ?? 0;
        $misses = $this->cacheStats['misses'] ?? 0;
        $sets = $this->cacheStats['sets'] ?? 0;
        $total = $hits + $misses;
        $hitRate = $total > 0 ? round(($hits / $total) * 100, 1) : 0.0;

        return [
            'available' => true,
            'hits' => $hits,
            'misses' => $misses,
            'sets' => $sets,
            'hit_rate' => $hitRate,
        ];
    }

    /** @return array<int, array{call: string, file: string}> */
    private function buildTrace(): array
    {
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);
        $out = [];
        $root = (string) ($this->flags['root_path'] ?? '');
        foreach ($trace as $frame) {
            $file = (string) ($frame['file'] ?? '');
            if ($file === '') {
                continue;
            }
            if (str_contains($file, DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR)) {
                continue;
            }
            if (str_contains($file, DIRECTORY_SEPARATOR . 'DevTools' . DIRECTORY_SEPARATOR)) {
                continue;
            }
            $line = (int) ($frame['line'] ?? 0);
            $class = (string) ($frame['class'] ?? '');
            $func = (string) ($frame['function'] ?? '');
            if ($root !== '' && str_starts_with($file, $root)) {
                $file = ltrim(substr($file, strlen($root)), DIRECTORY_SEPARATOR);
            }
            $out[] = [
                'call' => $class . ($class !== '' ? '->' : '') . $func . '()',
                'file' => $file . ':' . $line,
            ];
            if (count($out) >= 10) {
                break;
            }
        }

        return $out;
    }
}
