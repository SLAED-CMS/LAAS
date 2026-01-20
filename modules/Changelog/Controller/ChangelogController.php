<?php
declare(strict_types=1);

namespace Laas\Modules\Changelog\Controller;

use Laas\Core\Container\Container;
use Laas\Domain\Settings\SettingsReadServiceInterface;
use Laas\Http\Request;
use Laas\Http\Response;
use Laas\Modules\Changelog\Service\ChangelogService;
use Laas\Modules\Changelog\Support\ChangelogCache;
use Laas\Modules\Changelog\Support\ChangelogSettings;
use Laas\View\View;
use RuntimeException;
use Throwable;

final class ChangelogController
{
    public function __construct(
        private View $view,
        private ?SettingsReadServiceInterface $settingsService = null,
        private ?Container $container = null
    ) {
    }

    public function index(Request $request, array $params = []): Response
    {
        $settings = $this->loadSettings();
        $enabled = (bool) ($settings['enabled'] ?? false);
        if (!$enabled) {
            $filters = $this->filtersFromRequest($request);
            return $this->renderList($request, [], 1, 1, false, false, $filters);
        }

        $page = max(1, (int) ($request->query('page') ?? 1));
        $includeMerges = $request->query('merges') !== null
            ? (string) $request->query('merges') === '1'
            : (bool) ($settings['show_merges'] ?? false);
        $filters = $this->filtersFromRequest($request);

        $service = new ChangelogService($this->rootPath(), new ChangelogCache($this->rootPath()));

        try {
            $pageData = $service->fetchPage($settings, $page, $includeMerges, $filters);
            $data = $pageData->toArray();
        } catch (RuntimeException) {
            $data = [
                'commits' => [],
                'page' => $page,
                'per_page' => (int) ($settings['per_page'] ?? 20),
                'has_more' => false,
            ];
        }

        return $this->renderList(
            $request,
            $data['commits'] ?? [],
            (int) ($data['page'] ?? $page),
            (int) ($data['per_page'] ?? 20),
            (bool) ($data['has_more'] ?? false),
            $includeMerges,
            $filters
        );
    }

    /** @param array<string, string> $filters */
    private function renderList(Request $request, array $commits, int $page, int $perPage, bool $hasMore, bool $includeMerges, array $filters): Response
    {
        $groups = $this->groupCommitsByDate($commits);
        $querySuffix = $this->querySuffix($includeMerges, $filters);
        $viewData = [
            'commits' => $commits,
            'groups' => $groups,
            'page' => $page,
            'per_page' => $perPage,
            'has_prev' => $page > 1,
            'has_next' => $hasMore,
            'prev_page' => $page > 1 ? $page - 1 : 1,
            'next_page' => $hasMore ? $page + 1 : $page,
            'include_merges' => $includeMerges ? '1' : '0',
            'include_merges_checked' => $includeMerges,
            'query_suffix' => $querySuffix,
            'filters' => $filters,
        ];

        if ($request->isHtmx()) {
            return $this->view->render('changelog/_list.html', $viewData, 200, [], [
                'render_partial' => true,
            ]);
        }

        return $this->view->render('changelog/index.html', $viewData);
    }

    private function loadSettings(): array
    {
        return ChangelogSettings::load($this->rootPath(), $this->settingsService());
    }

    private function settingsService(): ?SettingsReadServiceInterface
    {
        if ($this->settingsService !== null) {
            return $this->settingsService;
        }

        if ($this->container !== null) {
            try {
                $service = $this->container->get(SettingsReadServiceInterface::class);
                if ($service instanceof SettingsReadServiceInterface) {
                    $this->settingsService = $service;
                    return $this->settingsService;
                }
            } catch (Throwable) {
                return null;
            }
        }

        return null;
    }

    private function rootPath(): string
    {
        return dirname(__DIR__, 3);
    }

    /** @return array<string, string> */
    private function filtersFromRequest(Request $request): array
    {
        $author = trim((string) ($request->query('author') ?? ''));
        $search = trim((string) ($request->query('search') ?? ''));
        $file = trim((string) ($request->query('file') ?? ''));
        $dateFrom = $this->sanitizeDate((string) ($request->query('datefrom') ?? ''));
        $dateTo = $this->sanitizeDate((string) ($request->query('dateto') ?? ''));

        return [
            'author' => $author,
            'search' => $search,
            'file' => $file,
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
        ];
    }

    private function sanitizeDate(string $value): string
    {
        $value = trim($value);
        if ($value === '' || !preg_match('/^\\d{4}-\\d{2}-\\d{2}$/', $value)) {
            return '';
        }
        $dt = \DateTime::createFromFormat('Y-m-d', $value);
        if ($dt === false || $dt->format('Y-m-d') !== $value) {
            return '';
        }
        return $value;
    }

    /** @param array<int, array<string, mixed>> $commits */
    private function groupCommitsByDate(array $commits): array
    {
        $grouped = [];
        foreach ($commits as $commit) {
            $commit = $this->formatCommit($commit);
            $raw = (string) ($commit['committed_at'] ?? '');
            $date = $this->commitDate($raw);
            if (!isset($grouped[$date])) {
                $grouped[$date] = [];
            }
            $grouped[$date][] = $commit;
        }

        $out = [];
        foreach ($grouped as $date => $items) {
            $out[] = [
                'date' => $date,
                'items' => $items,
            ];
        }
        return $out;
    }

    private function commitDate(string $raw): string
    {
        if ($raw === '') {
            return 'Unknown';
        }
        $date = substr($raw, 0, 10);
        if (preg_match('/^\\d{4}-\\d{2}-\\d{2}$/', $date)) {
            return $date;
        }
        $parsed = strtotime($raw);
        if ($parsed !== false) {
            return date('Y-m-d', $parsed);
        }
        return 'Unknown';
    }

    /** @param array<string, string> $filters */
    private function querySuffix(bool $includeMerges, array $filters): string
    {
        $params = array_filter([
            'merges' => $includeMerges ? '1' : '',
            'author' => $filters['author'] ?? '',
            'search' => $filters['search'] ?? '',
            'file' => $filters['file'] ?? '',
            'datefrom' => $filters['date_from'] ?? '',
            'dateto' => $filters['date_to'] ?? '',
        ], static fn(string $value): bool => $value !== '');

        if ($params === []) {
            return '';
        }

        return '&' . http_build_query($params);
    }

    /** @param array<string, mixed> $commit */
    private function formatCommit(array $commit): array
    {
        $title = (string) ($commit['title'] ?? '');
        $commit['title_is_release'] = $this->isReleaseTitle($title);
        $commit['title_clean'] = $this->cleanReleaseTitle($title);
        $commit['body_blocks'] = $this->bodyBlocks((string) ($commit['body'] ?? ''));
        return $commit;
    }

    private function isReleaseTitle(string $title): bool
    {
        $trimmed = ltrim($title);
        if ($trimmed === '') {
            return false;
        }
        if (str_starts_with($trimmed, '#')) {
            return true;
        }
        return (bool) preg_match('/^v\\d+(\\.\\d+){1,2}/i', $trimmed);
    }

    private function cleanReleaseTitle(string $title): string
    {
        $trimmed = ltrim($title);
        if ($trimmed === '') {
            return '';
        }
        if (str_starts_with($trimmed, '#')) {
            return trim(ltrim($trimmed, '#'));
        }
        return $trimmed;
    }

    /** @return array<int, array<string, mixed>> */
    private function bodyBlocks(string $body): array
    {
        $lines = preg_split("/\\r?\\n/", $body) ?: [];
        $blocks = [];
        $currentList = null;

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                $currentList = null;
                continue;
            }

            $isList = (bool) preg_match('/^([-*+]|\\d+\\.)\\s+/', $line);
            if ($isList) {
                $item = preg_replace('/^([-*+]|\\d+\\.)\\s+/', '', $line) ?? $line;
                if ($currentList === null) {
            $blocks[] = [
                'is_list' => true,
                'items' => [$item],
                'text' => '',
            ];
                    $currentList = count($blocks) - 1;
                } else {
                    $blocks[$currentList]['items'][] = $item;
                }
                continue;
            }

            $blocks[] = [
                'is_list' => false,
                'items' => [],
                'text' => $line,
            ];
            $currentList = null;
        }

        return $blocks;
    }
}
