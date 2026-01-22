<?php

declare(strict_types=1);

namespace Laas\Domain\AdminSearch;

use Laas\Core\FeatureFlagsInterface;
use Laas\Domain\Media\MediaService;
use Laas\Domain\Menus\MenusService;
use Laas\Domain\Pages\PagesService;
use Laas\Domain\Security\SecurityReportsService;
use Laas\Domain\Users\UsersService;
use Laas\Modules\ModuleCatalog;
use Laas\Support\Search\Highlighter;
use Laas\Support\Search\SearchNormalizer;
use Throwable;

class AdminSearchService implements AdminSearchServiceInterface
{
    private const DEFAULT_GROUP_LIMIT = 5;
    private const DEFAULT_GLOBAL_LIMIT = 25;
    private const MIN_QUERY_LENGTH = 2;
    private const MAX_QUERY_LENGTH = 80;

    public function __construct(
        private PagesService $pages,
        private MediaService $media,
        private UsersService $users,
        private MenusService $menus,
        private ModuleCatalog $modules,
        private ?SecurityReportsService $securityReports = null,
        private ?FeatureFlagsInterface $featureFlags = null
    ) {
    }

    /** @return array<string, mixed> */
    public function search(string $q, array $opts = []): array
    {
        $normalized = $this->normalizeQuery($q);
        $groupLimit = $this->normalizeLimit($opts['group_limit'] ?? null, self::DEFAULT_GROUP_LIMIT);
        $globalLimit = $this->normalizeLimit($opts['global_limit'] ?? null, self::DEFAULT_GLOBAL_LIMIT);
        $includeCommandsOnEmpty = (bool) ($opts['include_commands_on_empty'] ?? false);

        $groups = $this->baseGroups();
        $result = [
            'q' => $normalized['q'],
            'total' => 0,
            'groups' => $groups,
            'reason' => $normalized['reason'],
        ];

        if ($normalized['reason'] !== null) {
            return $result;
        }
        if ($normalized['q'] === '') {
            if ($includeCommandsOnEmpty) {
                $items = $this->searchCommands('', $groupLimit, $opts);
                $result['groups']['commands']['items'] = $items;
                $result['groups']['commands']['count'] = count($items);
                $result['total'] = count($items);
            }
            return $result;
        }

        $canPages = (bool) ($opts['can_pages'] ?? false);
        $canMedia = (bool) ($opts['can_media'] ?? false);
        $canUsers = (bool) ($opts['can_users'] ?? false);
        $canMenus = (bool) ($opts['can_menus'] ?? false);
        $canModules = (bool) ($opts['can_modules'] ?? false);
        $canSecurity = (bool) ($opts['can_security_reports'] ?? false);
        $canOps = (bool) ($opts['can_ops'] ?? false);

        $remaining = $globalLimit;
        $total = 0;

        if ($remaining > 0) {
            $items = $this->searchCommands($normalized['q'], min($groupLimit, $remaining), $opts);
            $result['groups']['commands']['items'] = $items;
            $result['groups']['commands']['count'] = count($items);
            $total += count($items);
            $remaining -= count($items);
        }

        if ($canPages && $remaining > 0) {
            $items = $this->searchPages($normalized['q'], min($groupLimit, $remaining));
            $result['groups']['pages']['items'] = $items;
            $result['groups']['pages']['count'] = count($items);
            $total += count($items);
            $remaining -= count($items);
        }

        if ($canMedia && $remaining > 0) {
            $items = $this->searchMedia($normalized['q'], min($groupLimit, $remaining));
            $result['groups']['media']['items'] = $items;
            $result['groups']['media']['count'] = count($items);
            $total += count($items);
            $remaining -= count($items);
        }

        if ($canUsers && $remaining > 0) {
            $items = $this->searchUsers($normalized['q'], min($groupLimit, $remaining));
            $result['groups']['users']['items'] = $items;
            $result['groups']['users']['count'] = count($items);
            $total += count($items);
            $remaining -= count($items);
        }

        if ($canMenus && $remaining > 0) {
            $items = $this->searchMenus($normalized['q'], min($groupLimit, $remaining));
            $result['groups']['menus']['items'] = $items;
            $result['groups']['menus']['count'] = count($items);
            $total += count($items);
            $remaining -= count($items);
        }

        if ($canModules && $remaining > 0) {
            $items = $this->searchModules($normalized['q'], min($groupLimit, $remaining));
            $result['groups']['modules']['items'] = $items;
            $result['groups']['modules']['count'] = count($items);
            $total += count($items);
            $remaining -= count($items);
        }

        if ($canSecurity && $remaining > 0) {
            $items = $this->searchSecurityReports($normalized['q'], min($groupLimit, $remaining));
            $result['groups']['security_reports']['items'] = $items;
            $result['groups']['security_reports']['count'] = count($items);
            $total += count($items);
            $remaining -= count($items);
        }

        if ($canOps && $remaining > 0) {
            $items = $this->searchOps($normalized['q']);
            $result['groups']['ops']['items'] = $items;
            $result['groups']['ops']['count'] = count($items);
            $total += count($items);
            $remaining -= count($items);
        }

        if ($remaining > 0) {
            $items = $this->searchAi($normalized['q']);
            $result['groups']['ai']['items'] = $items;
            $result['groups']['ai']['count'] = count($items);
            $total += count($items);
            $remaining -= count($items);
        }

        $result['total'] = $total;

        return $result;
    }

    /** @return array<string, mixed> */
    private function normalizeQuery(string $value): array
    {
        $query = SearchNormalizer::normalize($value);
        $query = preg_replace('/[^a-zA-Z0-9 _@\\-.:\\/]/', '', $query) ?? '';
        $query = trim($query);

        if ($query === '') {
            return ['q' => '', 'reason' => null];
        }

        if (strlen($query) > self::MAX_QUERY_LENGTH) {
            $query = substr($query, 0, self::MAX_QUERY_LENGTH);
        }

        if (strlen($query) < self::MIN_QUERY_LENGTH) {
            return ['q' => $query, 'reason' => 'too_short'];
        }

        return ['q' => $query, 'reason' => null];
    }

    /** @return array<string, array{key: string, title: string, title_key: string, count: int, items: array<int, array<string, mixed>>}> */
    private function baseGroups(): array
    {
        return [
            'commands' => $this->group('commands', 'Commands', 'admin.search.scope.commands'),
            'pages' => $this->group('pages', 'Pages', 'admin.search.scope.pages'),
            'media' => $this->group('media', 'Media', 'admin.search.scope.media'),
            'users' => $this->group('users', 'Users', 'admin.search.scope.users'),
            'menus' => $this->group('menus', 'Menus', 'admin.search.scope.menus'),
            'modules' => $this->group('modules', 'Modules', 'admin.search.scope.modules'),
            'ai' => $this->group('ai', 'AI', 'admin.search.scope.ai'),
            'security_reports' => $this->group('security_reports', 'Security Reports', 'admin.search.scope.security_reports'),
            'ops' => $this->group('ops', 'Ops', 'admin.search.scope.ops'),
        ];
    }

    /** @return array{key: string, title: string, title_key: string, count: int, items: array<int, array<string, mixed>>} */
    private function group(string $key, string $title, string $titleKey): array
    {
        return [
            'key' => $key,
            'title' => $title,
            'title_key' => $titleKey,
            'count' => 0,
            'items' => [],
        ];
    }

    private function normalizeLimit(mixed $value, int $default): int
    {
        if (!is_numeric($value)) {
            return $default;
        }

        $limit = (int) $value;
        if ($limit <= 0) {
            return $default;
        }

        return min($limit, 50);
    }

    /** @return array<int, array<string, mixed>> */
    public function commands(): array
    {
        return [
            [
                'id' => 'pages.new',
                'title' => 'New page',
                'hint' => 'Create a page',
                'url' => '/admin/pages/new',
                'icon' => 'file-earmark-plus',
                'section' => 'Pages',
                'hotkey' => 'P',
                'requires' => ['can_pages'],
            ],
            [
                'id' => 'pages.list',
                'title' => 'Pages',
                'hint' => 'Manage pages',
                'url' => '/admin/pages',
                'icon' => 'file-earmark-text',
                'section' => 'Pages',
                'requires' => ['can_pages'],
            ],
            [
                'id' => 'media.library',
                'title' => 'Media library',
                'hint' => 'Browse uploads',
                'url' => '/admin/media',
                'icon' => 'images',
                'section' => 'Media',
                'requires' => ['can_media'],
            ],
            [
                'id' => 'menus.list',
                'title' => 'Menus',
                'hint' => 'Navigation menus',
                'url' => '/admin/menus',
                'icon' => 'list',
                'section' => 'Menus',
                'requires' => ['can_menus'],
            ],
            [
                'id' => 'users.list',
                'title' => 'Users',
                'hint' => 'Manage users',
                'url' => '/admin/users',
                'icon' => 'people',
                'section' => 'Users',
                'requires' => ['can_users'],
            ],
            [
                'id' => 'settings',
                'title' => 'Settings',
                'hint' => 'Site settings',
                'url' => '/admin/settings',
                'icon' => 'gear',
                'section' => 'System',
                'requires' => ['can_settings'],
            ],
            [
                'id' => 'modules',
                'title' => 'Modules',
                'hint' => 'Enable/disable modules',
                'url' => '/admin/modules',
                'icon' => 'grid',
                'section' => 'System',
                'requires' => ['can_modules'],
            ],
            [
                'id' => 'security.reports',
                'title' => 'Security reports',
                'hint' => 'Review violations',
                'url' => '/admin/security-reports',
                'icon' => 'shield-exclamation',
                'section' => 'Security',
                'requires' => ['can_security_reports'],
            ],
            [
                'id' => 'ops',
                'title' => 'Ops dashboard',
                'hint' => 'Health and cache',
                'url' => '/admin/ops',
                'icon' => 'activity',
                'section' => 'System',
                'requires' => ['can_ops'],
            ],
            [
                'id' => 'themes',
                'title' => 'Theme inspector',
                'hint' => 'Theme API v2',
                'url' => '/admin/themes',
                'icon' => 'palette',
                'section' => 'System',
                'requires' => ['can_settings'],
                'feature_flag' => FeatureFlagsInterface::DEVTOOLS_THEME_INSPECTOR,
            ],
            [
                'id' => 'headless.playground',
                'title' => 'Headless playground',
                'hint' => 'Try /api/v2',
                'url' => '/admin/headless-playground',
                'icon' => 'terminal',
                'section' => 'API',
                'requires' => ['can_access'],
                'feature_flag' => FeatureFlagsInterface::DEVTOOLS_HEADLESS_PLAYGROUND,
            ],
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function searchCommands(string $query, int $limit, array $opts): array
    {
        $needle = strtolower($query);
        $items = [];
        foreach ($this->commands() as $command) {
            if (!$this->commandAllowed($command, $opts)) {
                continue;
            }
            $title = (string) ($command['title'] ?? '');
            $hint = (string) ($command['hint'] ?? '');
            $section = (string) ($command['section'] ?? '');
            $searchable = strtolower(trim($title . ' ' . $hint . ' ' . $section));
            if ($needle !== '' && !str_contains($searchable, $needle)) {
                continue;
            }
            $items[] = [
                'title' => $title,
                'subtitle' => $hint !== '' ? $hint : $section,
                'url' => (string) ($command['url'] ?? ''),
                'badge' => $section !== '' ? strtoupper($section) : 'CMD',
                'icon' => (string) ($command['icon'] ?? ''),
                'section' => $section,
                'hotkey' => $command['hotkey'] ?? null,
                'id' => $command['id'] ?? null,
                'title_segments' => Highlighter::segments($title, $query),
                'subtitle_segments' => Highlighter::segments($hint !== '' ? $hint : $section, $query),
            ];
            if (count($items) >= $limit) {
                break;
            }
        }

        return $items;
    }

    private function commandAllowed(array $command, array $opts): bool
    {
        $flag = (string) ($command['feature_flag'] ?? '');
        if ($flag !== '' && $this->featureFlags instanceof FeatureFlagsInterface) {
            if (!$this->featureFlags->isEnabled($flag)) {
                return false;
            }
        }

        $requirements = $command['requires'] ?? [];
        if (is_string($requirements)) {
            $requirements = [$requirements];
        }
        if (!is_array($requirements)) {
            return true;
        }
        foreach ($requirements as $key) {
            if (!is_string($key) || $key === '') {
                continue;
            }
            if (!isset($opts[$key]) || $opts[$key] !== true) {
                return false;
            }
        }
        return true;
    }

    /** @return array<int, array<string, mixed>> */
    private function searchPages(string $query, int $limit): array
    {
        $rows = $this->pages->list([
            'query' => $query,
            'limit' => $limit,
            'offset' => 0,
        ]);

        $items = [];
        foreach ($rows as $row) {
            $id = (int) ($row['id'] ?? 0);
            $title = (string) ($row['title'] ?? '');
            $slug = (string) ($row['slug'] ?? '');
            $status = (string) ($row['status'] ?? '');
            $items[] = [
                'title' => $title,
                'subtitle' => $slug !== '' ? $slug : $status,
                'url' => $id > 0 ? '/admin/pages/' . $id . '/edit' : '/admin/pages',
                'badge' => $status !== '' ? strtoupper($status) : 'PAGE',
                'title_segments' => Highlighter::segments($title, $query),
                'subtitle_segments' => Highlighter::segments($slug !== '' ? $slug : $status, $query),
                'meta' => [
                    'id' => $id,
                    'status' => $status,
                ],
            ];
        }

        return $items;
    }

    /** @return array<int, array<string, mixed>> */
    private function searchMedia(string $query, int $limit): array
    {
        $rows = $this->media->search($query, $limit, 0);

        $items = [];
        foreach ($rows as $row) {
            $id = (int) ($row['id'] ?? 0);
            $name = (string) ($row['original_name'] ?? '');
            $mime = (string) ($row['mime_type'] ?? '');
            $items[] = [
                'title' => $name,
                'subtitle' => $mime,
                'url' => $id > 0 ? '/media/' . $id . '/file' : '#',
                'badge' => $mime !== '' ? strtoupper($mime) : 'MEDIA',
                'title_segments' => Highlighter::segments($name, $query),
                'subtitle_segments' => Highlighter::segments($mime, $query),
                'meta' => [
                    'id' => $id,
                ],
            ];
        }

        return $items;
    }

    /** @return array<int, array<string, mixed>> */
    private function searchUsers(string $query, int $limit): array
    {
        $rows = $this->users->list([
            'query' => $query,
            'limit' => $limit,
            'offset' => 0,
        ]);

        $items = [];
        foreach ($rows as $row) {
            $id = (int) ($row['id'] ?? 0);
            $username = (string) ($row['username'] ?? '');
            $email = (string) ($row['email'] ?? '');
            $subtitle = $email !== '' ? $email : (string) ($row['role'] ?? '');
            $items[] = [
                'title' => $username,
                'subtitle' => $subtitle,
                'url' => $id > 0 ? '/admin/users#user-' . $id : '/admin/users',
                'badge' => 'USER',
                'title_segments' => Highlighter::segments($username, $query),
                'subtitle_segments' => Highlighter::segments($subtitle, $query),
                'meta' => [
                    'id' => $id,
                ],
            ];
        }

        return $items;
    }

    /** @return array<int, array<string, mixed>> */
    private function searchMenus(string $query, int $limit): array
    {
        $rows = $this->menus->search($query, $limit, 0);

        $items = [];
        foreach ($rows as $row) {
            $id = (int) ($row['id'] ?? 0);
            $name = (string) ($row['name'] ?? '');
            $title = (string) ($row['title'] ?? '');
            $subtitle = $title !== '' ? $title : $name;
            $items[] = [
                'title' => $name !== '' ? $name : $title,
                'subtitle' => $subtitle,
                'url' => $id > 0 ? '/admin/menus#menu-' . $id : '/admin/menus',
                'badge' => 'MENU',
                'title_segments' => Highlighter::segments($name !== '' ? $name : $title, $query),
                'subtitle_segments' => Highlighter::segments($subtitle, $query),
                'meta' => [
                    'id' => $id,
                ],
            ];
        }

        return $items;
    }

    /** @return array<int, array<string, mixed>> */
    private function searchModules(string $query, int $limit): array
    {
        $items = [];
        $needle = strtolower($query);
        foreach ($this->modules->listAll() as $module) {
            if (!is_array($module)) {
                continue;
            }
            $name = (string) ($module['name'] ?? '');
            $type = (string) ($module['type'] ?? '');
            $notes = (string) ($module['notes'] ?? '');
            $adminUrl = (string) ($module['admin_url'] ?? '');

            $haystack = strtolower($name . ' ' . $type . ' ' . $notes);
            if ($needle === '' || !str_contains($haystack, $needle)) {
                continue;
            }

            $enabled = (bool) ($module['enabled'] ?? false);
            $items[] = [
                'title' => $name,
                'subtitle' => $type,
                'url' => $adminUrl !== '' ? $adminUrl : '/admin/modules',
                'badge' => $enabled ? 'ON' : 'OFF',
                'title_segments' => Highlighter::segments($name, $query),
                'subtitle_segments' => Highlighter::segments($type, $query),
                'meta' => [
                    'enabled' => $enabled,
                    'type' => $type,
                ],
            ];

            if (count($items) >= $limit) {
                break;
            }
        }

        return $items;
    }

    /** @return array<int, array<string, mixed>> */
    private function searchSecurityReports(string $query, int $limit): array
    {
        if ($this->securityReports === null) {
            return [];
        }

        try {
            $rows = $this->securityReports->list([
                'search' => $query,
                'limit' => $limit,
                'offset' => 0,
            ]);
        } catch (Throwable) {
            return [];
        }

        $items = [];
        foreach ($rows as $row) {
            $id = (int) ($row['id'] ?? 0);
            $type = strtoupper((string) ($row['type'] ?? ''));
            $directive = (string) ($row['violated_directive'] ?? '');
            $items[] = [
                'title' => $type !== '' ? $type . ' report' : 'Security report',
                'subtitle' => $directive,
                'url' => $id > 0 ? '/admin/security-reports/' . $id : '/admin/security-reports',
                'badge' => $type !== '' ? $type : 'SEC',
                'title_segments' => Highlighter::segments($type !== '' ? $type . ' report' : 'Security report', $query),
                'subtitle_segments' => Highlighter::segments($directive, $query),
                'meta' => [
                    'id' => $id,
                ],
            ];
        }

        return $items;
    }

    /** @return array<int, array<string, mixed>> */
    private function searchOps(string $query): array
    {
        $needle = strtolower($query);
        if (!str_contains($needle, 'ops') && !str_contains($needle, 'health') && !str_contains($needle, 'status')) {
            return [];
        }

        return [[
            'title' => 'Ops Dashboard',
            'subtitle' => 'Health, backups, cache, security',
            'url' => '/admin/ops',
            'badge' => 'OPS',
            'title_segments' => Highlighter::segments('Ops Dashboard', $query),
            'subtitle_segments' => Highlighter::segments('Health, backups, cache, security', $query),
            'meta' => [],
        ]];
    }

    /** @return array<int, array<string, mixed>> */
    private function searchAi(string $query): array
    {
        $needle = strtolower($query);
        if (!str_contains($needle, 'ai') && !str_contains($needle, 'assistant') && !str_contains($needle, 'proposal')) {
            return [];
        }

        return [[
            'title' => 'AI Assistant',
            'subtitle' => 'Admin AI proposals & tools',
            'url' => '/admin/ai',
            'badge' => 'AI',
            'title_segments' => Highlighter::segments('AI Assistant', $query),
            'subtitle_segments' => Highlighter::segments('Admin AI proposals & tools', $query),
            'meta' => [],
        ]];
    }
}
