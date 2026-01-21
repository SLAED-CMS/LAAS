<?php
declare(strict_types=1);

namespace Laas\Modules\Changelog\Controller;

use Laas\Core\Container\Container;
use Laas\Domain\Rbac\RbacServiceInterface;
use Laas\Domain\Settings\SettingsReadServiceInterface;
use Laas\Domain\Settings\SettingsWriteServiceInterface;
use Laas\Http\ErrorResponse;
use Laas\Http\Request;
use Laas\Http\Response;
use Laas\Modules\Changelog\Service\ChangelogService;
use Laas\Modules\Changelog\Support\ChangelogCache;
use Laas\Modules\Changelog\Support\ChangelogSettings;
use Laas\Modules\Changelog\Support\ChangelogValidator;
use Laas\Support\Audit;
use Laas\View\View;
use RuntimeException;
use Throwable;

final class AdminChangelogController
{
    public function __construct(
        private View $view,
        private ?SettingsReadServiceInterface $settingsReadService = null,
        private ?SettingsWriteServiceInterface $settingsWriteService = null,
        private ?Container $container = null,
        private ?RbacServiceInterface $rbacService = null,
        private ?ChangelogService $changelogService = null
    ) {
    }

    public function index(Request $request): Response
    {
        if (!$this->canAdmin($request)) {
            return $this->forbidden($request);
        }

        $settings = $this->loadSettings();
        $sourceType = $request->query('source_type') ?? $settings['source_type'];

        if ($request->isHtmx() && $request->query('partial') === 'source') {
            return $this->renderSourcePartial((string) $sourceType, $settings, 200);
        }

        return $this->view->render('changelog/index.html', [
            'settings' => $settings,
            'source_type' => $sourceType,
            'source_is_github' => (string) $sourceType === 'github',
            'source_is_git' => (string) $sourceType === 'git',
            'token_mode_env' => (string) ($settings['github_token_mode'] ?? 'env') === 'env',
            'token_mode_db' => (string) ($settings['github_token_mode'] ?? 'env') === 'db',
            'token_db_allowed' => false,
            'token_db_disabled' => true,
            'token_db_disabled_attr' => 'disabled',
            'masked_repo_path' => $this->maskPath((string) ($settings['git_repo_path'] ?? '')),
            'success' => null,
            'errors' => [],
            'preview' => [],
            'preview_error' => null,
        ], 200, [], [
            'theme' => 'admin',
        ]);
    }

    public function save(Request $request): Response
    {
        if (!$this->canAdmin($request)) {
            return $this->forbidden($request);
        }

        $settings = $this->loadSettings();

        $post = $request->getPost();
        if (!is_array($post)) {
            $post = [];
        }
        if (($post['source_type'] ?? '') === '') {
            $post['source_type'] = $settings['source_type'] ?? 'github';
        }
        if (($post['source_type'] ?? '') === 'github' && (($post['git_repo_path'] ?? '') !== '' || ($post['git_binary_path'] ?? '') !== '')) {
            $post['source_type'] = 'git';
        }

        if (($post['git_repo_path'] ?? '') === '') {
            $post['git_repo_path'] = $settings['git_repo_path'] ?? $this->rootPath();
        }
        if (($post['git_binary_path'] ?? '') === '') {
            $post['git_binary_path'] = $settings['git_binary_path'] ?? 'git';
        }

        $validator = new ChangelogValidator();
        $result = $validator->validate($post, $this->rootPath(), false);
        $errors = $result['errors'];
        $values = $result['values'];

        if ($errors !== []) {
            return $this->renderFormPartial($values, null, $errors, 422);
        }

        $settingsService = $this->writeService();
        if ($settingsService === null) {
            return $this->renderFormPartial($values, null, ['changelog.admin.validation_failed'], 503);
        }

        $this->persistSettings($settingsService, $values);
        Audit::log('changelog.settings.update', 'changelog', null, [
            'source_type' => $values['source_type'],
            'branch' => $values['branch'],
            'per_page' => $values['per_page'],
            'cache_ttl_seconds' => $values['cache_ttl_seconds'],
            'actor_user_id' => $this->currentUserId($request),
            'actor_ip' => $request->ip(),
        ]);

        return $this->renderFormPartial($values, $this->view->translate('changelog.admin.save_ok'), [], 200);
    }

    public function test(Request $request): Response
    {
        if (!$this->canAdmin($request)) {
            return $this->forbidden($request);
        }

        $baseSettings = $this->loadSettings();
        $settings = $baseSettings;
        $post = $request->getPost();
        if (is_array($post)) {
            $settings = array_merge($settings, $post);
        }

        if (($settings['git_repo_path'] ?? '') === '') {
            $settings['git_repo_path'] = $baseSettings['git_repo_path'] ?? $this->rootPath();
        }
        if (($settings['git_binary_path'] ?? '') === '') {
            $settings['git_binary_path'] = $baseSettings['git_binary_path'] ?? 'git';
        }

        $validator = new ChangelogValidator();
        $result = $validator->validate($settings, $this->rootPath(), false);
        $errors = $result['errors'];
        $values = $result['values'];

        if ($errors !== []) {
            return $this->renderFormPartial($values, null, $errors, 422);
        }

        $service = $this->changelogService();
        if ($service === null) {
            return $this->renderFormPartial($values, null, ['changelog.admin.test_fail'], 503);
        }
        $provider = $service->buildProvider($values);
        $testResult = $provider->testConnection();

        Audit::log('changelog.source.tested', 'changelog', null, [
            'source_type' => $values['source_type'],
            'ok' => $testResult->ok,
            'actor_user_id' => $this->currentUserId($request),
            'actor_ip' => $request->ip(),
        ]);

        if ($testResult->ok) {
            return $this->renderFormPartial($values, $this->view->translate('changelog.admin.test_ok'), [], 200);
        }

        return $this->renderFormPartial($values, null, ['changelog.admin.test_fail'], 422);
    }

    public function preview(Request $request): Response
    {
        if (!$this->canAdmin($request)) {
            return $this->forbidden($request);
        }

        $settings = $this->loadSettings();
        $includeMerges = (bool) ($settings['show_merges'] ?? false);
        $service = $this->changelogService();
        if ($service === null) {
            return $this->view->render('changelog/preview_list.html', [
                'commits' => [],
                'groups' => [],
                'error' => $this->view->translate('changelog.admin.test_fail'),
                'source_type' => (string) ($settings['source_type'] ?? 'github'),
                'source_is_github' => (string) ($settings['source_type'] ?? 'github') === 'github',
                'source_is_git' => (string) ($settings['source_type'] ?? 'github') === 'git',
            ], 503, [], [
                'theme' => 'admin',
                'render_partial' => true,
            ]);
        }

        $commits = [];
        $error = null;
        try {
            $page = $service->fetchPage($settings, 1, $includeMerges, []);
            $commits = $page->toArray()['commits'] ?? [];
        } catch (RuntimeException) {
            $error = $this->view->translate('changelog.admin.test_fail');
        }

        $groups = $this->groupCommitsByDate($commits);

        return $this->view->render('changelog/preview_list.html', [
            'commits' => $commits,
            'groups' => $groups,
            'error' => $error,
            'source_type' => (string) ($settings['source_type'] ?? 'github'),
            'source_is_github' => (string) ($settings['source_type'] ?? 'github') === 'github',
            'source_is_git' => (string) ($settings['source_type'] ?? 'github') === 'git',
        ], 200, [], [
            'theme' => 'admin',
            'render_partial' => true,
        ]);
    }

    public function clearCache(Request $request): Response
    {
        if (!$this->canClearCache($request)) {
            return $this->forbidden($request);
        }

        $cache = new ChangelogCache($this->rootPath());
        $cache->clear();

        Audit::log('changelog.cache.cleared', 'changelog', null, [
            'actor_user_id' => $this->currentUserId($request),
            'actor_ip' => $request->ip(),
        ]);

        return $this->renderFormPartial($this->loadSettings(), $this->view->translate('changelog.admin.cache_cleared'), [], 200);
    }

    private function renderFormPartial(array $settings, ?string $success, array $errors, int $status): Response
    {
        return $this->view->render('changelog/form_partial.html', [
            'settings' => $settings,
            'source_type' => $settings['source_type'] ?? 'github',
            'source_is_github' => (string) ($settings['source_type'] ?? 'github') === 'github',
            'source_is_git' => (string) ($settings['source_type'] ?? 'github') === 'git',
            'token_mode_env' => (string) ($settings['github_token_mode'] ?? 'env') === 'env',
            'token_mode_db' => (string) ($settings['github_token_mode'] ?? 'env') === 'db',
            'token_db_allowed' => false,
            'token_db_disabled' => true,
            'token_db_disabled_attr' => 'disabled',
            'masked_repo_path' => $this->maskPath((string) ($settings['git_repo_path'] ?? '')),
            'success' => $success,
            'errors' => $this->translateErrors($errors),
        ], $status, [], [
            'theme' => 'admin',
            'render_partial' => true,
        ]);
    }

    private function renderSourcePartial(string $sourceType, array $settings, int $status): Response
    {
        return $this->view->render('changelog/source_fields.html', [
            'source_type' => $sourceType,
            'source_is_github' => $sourceType === 'github',
            'source_is_git' => $sourceType === 'git',
            'token_mode_env' => (string) ($settings['github_token_mode'] ?? 'env') === 'env',
            'token_mode_db' => (string) ($settings['github_token_mode'] ?? 'env') === 'db',
            'settings' => $settings,
            'token_db_allowed' => false,
            'token_db_disabled' => true,
            'token_db_disabled_attr' => 'disabled',
            'masked_repo_path' => $this->maskPath((string) ($settings['git_repo_path'] ?? '')),
        ], $status, [], [
            'theme' => 'admin',
            'render_partial' => true,
        ]);
    }

    private function translateErrors(array $errors): array
    {
        $messages = [];
        foreach ($errors as $error) {
            if (is_string($error)) {
                $messages[] = $this->view->translate($error);
            }
        }

        return $messages;
    }

    private function persistSettings(SettingsWriteServiceInterface $settings, array $values): void
    {
        $settings->setMany([
            'changelog.enabled' => (bool) $values['enabled'],
            'changelog.source_type' => (string) $values['source_type'],
            'changelog.cache_ttl_seconds' => (int) $values['cache_ttl_seconds'],
            'changelog.per_page' => (int) $values['per_page'],
            'changelog.show_merges' => (bool) $values['show_merges'],
            'changelog.branch' => (string) $values['branch'],
            'changelog.github_owner' => (string) $values['github_owner'],
            'changelog.github_repo' => (string) $values['github_repo'],
            'changelog.github_token_mode' => (string) $values['github_token_mode'],
            'changelog.github_token_env_key' => (string) $values['github_token_env_key'],
            'changelog.git_repo_path' => (string) $values['git_repo_path'],
            'changelog.git_binary_path' => (string) $values['git_binary_path'],
        ]);
    }

    private function loadSettings(): array
    {
        return ChangelogSettings::load($this->rootPath(), $this->readService());
    }

    private function readService(): ?SettingsReadServiceInterface
    {
        if ($this->settingsReadService !== null) {
            return $this->settingsReadService;
        }

        if ($this->container !== null) {
            try {
                $service = $this->container->get(SettingsReadServiceInterface::class);
                if ($service instanceof SettingsReadServiceInterface) {
                    $this->settingsReadService = $service;
                    return $this->settingsReadService;
                }
            } catch (Throwable) {
                return null;
            }
        }

        return null;
    }

    private function writeService(): ?SettingsWriteServiceInterface
    {
        if ($this->settingsWriteService !== null) {
            return $this->settingsWriteService;
        }

        if ($this->container !== null) {
            try {
                $service = $this->container->get(SettingsWriteServiceInterface::class);
                if ($service instanceof SettingsWriteServiceInterface) {
                    $this->settingsWriteService = $service;
                    return $this->settingsWriteService;
                }
            } catch (Throwable) {
                return null;
            }
        }

        return null;
    }

    private function changelogService(): ?ChangelogService
    {
        if ($this->changelogService !== null) {
            return $this->changelogService;
        }

        if ($this->container !== null) {
            try {
                $service = $this->container->get(ChangelogService::class);
                if ($service instanceof ChangelogService) {
                    $this->changelogService = $service;
                    return $this->changelogService;
                }
            } catch (Throwable) {
                return null;
            }
        }

        return null;
    }

    private function canAdmin(Request $request): bool
    {
        return $this->canPermission($request, 'changelog.admin');
    }

    private function canClearCache(Request $request): bool
    {
        return $this->canPermission($request, 'changelog.cache.clear');
    }

    private function canPermission(Request $request, string $permission): bool
    {
        $userId = $this->currentUserId($request);
        if ($userId === null) {
            return false;
        }

        $rbac = $this->rbacService();
        if ($rbac === null) {
            return false;
        }

        return $rbac->userHasPermission($userId, $permission);
    }

    private function rbacService(): ?RbacServiceInterface
    {
        if ($this->rbacService !== null) {
            return $this->rbacService;
        }

        if ($this->container !== null) {
            try {
                $service = $this->container->get(RbacServiceInterface::class);
                if ($service instanceof RbacServiceInterface) {
                    $this->rbacService = $service;
                    return $this->rbacService;
                }
            } catch (Throwable) {
                return null;
            }
        }

        return null;
    }

    private function currentUserId(Request $request): ?int
    {
        $session = $request->session();
        if (!$session->isStarted()) {
            return null;
        }

        $raw = $session->get('user_id');
        if (is_int($raw)) {
            return $raw;
        }
        if (is_string($raw) && ctype_digit($raw)) {
            return (int) $raw;
        }
        return null;
    }

    private function forbidden(Request $request): Response
    {
        return ErrorResponse::respondForRequest($request, 'forbidden', [], 403, [], 'admin.changelog');
    }

    private function rootPath(): string
    {
        return dirname(__DIR__, 3);
    }

    private function maskPath(string $path): string
    {
        $path = str_replace('\\', '/', trim($path));
        if ($path === '') {
            return '';
        }

        $parts = array_values(array_filter(explode('/', $path), static fn(string $part): bool => $part !== ''));
        $count = count($parts);
        if ($count <= 2) {
            return $path;
        }

        return $parts[0] . '/.../' . $parts[$count - 1];
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
