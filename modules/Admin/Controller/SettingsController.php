<?php
declare(strict_types=1);

namespace Laas\Modules\Admin\Controller;

use Laas\Database\DatabaseManager;
use Laas\Database\Repositories\RbacRepository;
use Laas\Database\Repositories\SettingsRepository;
use Laas\Http\Contract\ContractResponse;
use Laas\Http\Request;
use Laas\Http\Response;
use Laas\Support\AuditLogger;
use Laas\View\View;
use Throwable;

final class SettingsController
{
    public function __construct(
        private View $view,
        private ?DatabaseManager $db = null
    ) {
    }

    public function index(Request $request): Response
    {
        if (!$this->canManage($request)) {
            return $this->forbidden($request, 'admin.settings.index');
        }

        $appConfig = $this->loadAppConfig();
        $apiConfig = $this->loadApiConfig();
        $locales = $this->normalizeList($appConfig['locales'] ?? []);
        $themes = $this->discoverThemes();
        $tokenIssueModes = $this->apiTokenIssueModes();

        $defaults = [
            'site_name' => 'LAAS CMS',
            'default_locale' => (string) ($appConfig['default_locale'] ?? 'en'),
            'theme' => (string) ($appConfig['theme'] ?? 'default'),
            'api_token_issue_mode' => (string) ($apiConfig['token_issue_mode'] ?? 'admin'),
        ];

        $settings = $defaults;
        $sources = [
            'site_name' => 'CONFIG',
            'default_locale' => 'CONFIG',
            'theme' => 'CONFIG',
            'api_token_issue_mode' => 'CONFIG',
        ];
        $repo = $this->getRepository();
        if ($repo !== null) {
            $settings['site_name'] = (string) $repo->get('site_name', $defaults['site_name']);
            $settings['default_locale'] = (string) $repo->get('default_locale', $defaults['default_locale']);
            $settings['theme'] = (string) $repo->get('theme', $defaults['theme']);
            $settings['api_token_issue_mode'] = (string) $repo->get('api.token_issue_mode', $defaults['api_token_issue_mode']);
            $sources['site_name'] = $repo->has('site_name') ? 'DB' : 'CONFIG';
            $sources['default_locale'] = $repo->has('default_locale') ? 'DB' : 'CONFIG';
            $sources['theme'] = $repo->has('theme') ? 'DB' : 'CONFIG';
            $sources['api_token_issue_mode'] = $repo->has('api.token_issue_mode') ? 'DB' : 'CONFIG';
        }

        if (!in_array($settings['default_locale'], $locales, true)) {
            $settings['default_locale'] = $defaults['default_locale'];
        }
        if (!in_array($settings['theme'], $themes, true)) {
            $settings['theme'] = $defaults['theme'];
        }
        if (!array_key_exists($settings['api_token_issue_mode'], $tokenIssueModes)) {
            $settings['api_token_issue_mode'] = $defaults['api_token_issue_mode'];
        }

        if ($request->wantsJson()) {
            return ContractResponse::ok([
                'items' => $this->jsonItems($settings, $sources),
            ], [
                'route' => 'admin.settings.index',
            ]);
        }

        $saved = $request->query('saved') === '1';
        $error = $request->query('error') === '1';
        $successMessage = $saved ? $this->view->translate('admin.settings.saved') : null;
        $errorMessages = $error ? [$this->view->translate('admin.settings.error_invalid')] : [];

        return $this->view->render('pages/settings.html', [
            'settings' => $settings,
            'source' => $sources,
            'localesOptions' => $this->buildOptions($locales, $settings['default_locale']),
            'themesOptions' => $this->buildOptions($themes, $settings['theme']),
            'apiTokenIssueModeOptions' => $this->buildOptionsWithLabels($tokenIssueModes, $settings['api_token_issue_mode']),
            'success' => $successMessage,
            'errors' => $errorMessages,
            'form' => [
                'saved' => $saved,
                'error' => $error,
            ],
        ], 200, [], [
            'theme' => 'admin',
        ]);
    }

    public function save(Request $request): Response
    {
        if (!$this->canManage($request)) {
            return $this->forbidden($request, 'admin.settings.save');
        }

        $appConfig = $this->loadAppConfig();
        $apiConfig = $this->loadApiConfig();
        $locales = $this->normalizeList($appConfig['locales'] ?? []);
        $themes = $this->discoverThemes();
        $tokenIssueModes = $this->apiTokenIssueModes();

        $siteName = trim((string) ($request->post('site_name') ?? ''));
        $defaultLocale = (string) ($request->post('default_locale') ?? '');
        $theme = (string) ($request->post('theme') ?? '');
        $apiTokenIssueMode = (string) ($request->post('api_token_issue_mode') ?? ($apiConfig['token_issue_mode'] ?? 'admin'));

        $errors = [];
        if ($siteName === '' || strlen($siteName) > 80) {
            $errors[] = 'site_name';
        }
        if (!in_array($defaultLocale, $locales, true)) {
            $errors[] = 'default_locale';
        }
        if (!in_array($theme, $themes, true)) {
            $errors[] = 'theme';
        }
        if (!array_key_exists($apiTokenIssueMode, $tokenIssueModes)) {
            $errors[] = 'api_token_issue_mode';
        }

        $repo = $this->getRepository();
        if ($repo === null) {
            return $this->saveErrorResponse($request, $siteName, $defaultLocale, $theme, $apiTokenIssueMode, $locales, $themes, $tokenIssueModes, 503, $errors);
        }

        if ($errors !== []) {
            return $this->saveErrorResponse($request, $siteName, $defaultLocale, $theme, $apiTokenIssueMode, $locales, $themes, $tokenIssueModes, 422, $errors);
        }

        $repo->set('site_name', $siteName, 'string');
        $repo->set('default_locale', $defaultLocale, 'string');
        $repo->set('theme', $theme, 'string');
        $repo->set('api.token_issue_mode', $apiTokenIssueMode, 'string');
        (new AuditLogger($this->db, $request->session()))->log(
            'settings.update',
            'setting',
            null,
            [
                'site_name' => $siteName,
                'default_locale' => $defaultLocale,
                'theme' => $theme,
                'api_token_issue_mode' => $apiTokenIssueMode,
            ],
            $this->currentUserId($request),
            $request->ip()
        );

        if ($request->wantsJson()) {
            return ContractResponse::ok([
                'saved' => true,
                'updated' => $this->jsonUpdated($siteName, $defaultLocale, $theme, $apiTokenIssueMode),
            ], [
                'status' => 'ok',
                'route' => 'admin.settings.save',
            ]);
        }

        if ($request->isHtmx()) {
            return $this->renderFormPartial($siteName, $defaultLocale, $theme, $apiTokenIssueMode, $locales, $themes, $tokenIssueModes, true, false, 200, [
                'site_name' => 'DB',
                'default_locale' => 'DB',
                'theme' => 'DB',
                'api_token_issue_mode' => 'DB',
            ]);
        }

        return new Response('', 302, [
            'Location' => '/admin/settings?saved=1',
        ]);
    }

    private function saveErrorResponse(
        Request $request,
        string $siteName,
        string $defaultLocale,
        string $theme,
        string $apiTokenIssueMode,
        array $locales,
        array $themes,
        array $tokenIssueModes,
        int $status,
        array $errors
    ): Response {
        if ($request->wantsJson()) {
            if ($status === 422) {
                return ContractResponse::error('validation_failed', [
                    'route' => 'admin.settings.save',
                ], 422, $this->jsonValidationFields($errors));
            }
            if ($status === 503) {
                return ContractResponse::error('service_unavailable', [
                    'route' => 'admin.settings.save',
                ], 503);
            }
            return ContractResponse::error('invalid_request', [
                'route' => 'admin.settings.save',
            ], $status);
        }

        $sources = [
            'site_name' => 'DB',
            'default_locale' => 'DB',
            'theme' => 'DB',
            'api_token_issue_mode' => 'DB',
        ];
        $repo = $this->getRepository();
        if ($repo !== null) {
            $sources['site_name'] = $repo->has('site_name') ? 'DB' : 'CONFIG';
            $sources['default_locale'] = $repo->has('default_locale') ? 'DB' : 'CONFIG';
            $sources['theme'] = $repo->has('theme') ? 'DB' : 'CONFIG';
            $sources['api_token_issue_mode'] = $repo->has('api.token_issue_mode') ? 'DB' : 'CONFIG';
        }

        if ($request->isHtmx()) {
            return $this->renderFormPartial($siteName, $defaultLocale, $theme, $apiTokenIssueMode, $locales, $themes, $tokenIssueModes, false, true, $status, $sources);
        }

        return new Response('', 302, [
            'Location' => '/admin/settings?error=1',
        ]);
    }

    private function renderFormPartial(
        string $siteName,
        string $defaultLocale,
        string $theme,
        string $apiTokenIssueMode,
        array $locales,
        array $themes,
        array $tokenIssueModes,
        bool $saved,
        bool $error,
        int $status,
        array $sources = []
    ): Response {
        return $this->view->render('partials/settings_form.html', [
            'settings' => [
                'site_name' => $siteName,
                'default_locale' => $defaultLocale,
                'theme' => $theme,
                'api_token_issue_mode' => $apiTokenIssueMode,
            ],
            'source' => $sources,
            'localesOptions' => $this->buildOptions($locales, $defaultLocale),
            'themesOptions' => $this->buildOptions($themes, $theme),
            'apiTokenIssueModeOptions' => $this->buildOptionsWithLabels($tokenIssueModes, $apiTokenIssueMode),
            'success' => $saved ? $this->view->translate('admin.settings.saved') : null,
            'errors' => $error ? [$this->view->translate('admin.settings.error_invalid')] : [],
            'form' => [
                'saved' => $saved,
                'error' => $error,
            ],
        ], $status, [], [
            'theme' => 'admin',
        ]);
    }

    private function getRepository(): ?SettingsRepository
    {
        if ($this->db === null || !$this->db->healthCheck()) {
            return null;
        }

        try {
            return new SettingsRepository($this->db->pdo());
        } catch (Throwable) {
            return null;
        }
    }

    private function loadAppConfig(): array
    {
        $configPath = dirname(__DIR__, 3) . '/config/app.php';
        $configPath = realpath($configPath) ?: $configPath;
        if (!is_file($configPath)) {
            return [];
        }

        $config = require $configPath;
        return is_array($config) ? $config : [];
    }

    private function loadApiConfig(): array
    {
        $configPath = dirname(__DIR__, 3) . '/config/api.php';
        $configPath = realpath($configPath) ?: $configPath;
        if (!is_file($configPath)) {
            return [];
        }

        $config = require $configPath;
        return is_array($config) ? $config : [];
    }

    private function discoverThemes(): array
    {
        $themesDir = dirname(__DIR__, 3) . '/themes';
        $themesDir = realpath($themesDir) ?: $themesDir;
        if (!is_dir($themesDir)) {
            return [];
        }

        $items = scandir($themesDir) ?: [];
        $themes = [];
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $themesDir . '/' . $item;
            if (!is_dir($path)) {
                continue;
            }

            $hasLayout = is_file($path . '/layout.html');
            $hasMeta = is_file($path . '/theme.json');
            if (!$hasLayout && !$hasMeta) {
                continue;
            }

            $themes[] = $item;
        }

        sort($themes);
        return $themes;
    }

    private function normalizeList(array $values): array
    {
        $out = [];
        foreach ($values as $value) {
            if (is_string($value)) {
                $out[] = $value;
            }
        }

        return $out;
    }

    private function buildOptions(array $values, string $selected): array
    {
        $options = [];
        foreach ($values as $value) {
            $options[] = [
                'value' => $value,
                'label' => $value,
                'selected_attr' => $value === $selected ? 'selected' : '',
            ];
        }

        return $options;
    }

    private function buildOptionsWithLabels(array $values, string $selected): array
    {
        $options = [];
        foreach ($values as $value => $label) {
            $options[] = [
                'value' => $value,
                'label' => $label,
                'selected_attr' => $value === $selected ? 'selected' : '',
            ];
        }

        return $options;
    }

    private function apiTokenIssueModes(): array
    {
        return [
            'admin' => $this->view->translate('admin.settings.api_token_issue_mode.admin'),
            'admin_or_password' => $this->view->translate('admin.settings.api_token_issue_mode.admin_or_password'),
        ];
    }

    /** @return array<int, array{key: string, value: string, source: string, type: string}> */
    private function jsonItems(array $settings, array $sources): array
    {
        $keys = ['site_name', 'default_locale', 'theme', 'api_token_issue_mode'];
        $items = [];
        foreach ($keys as $key) {
            $items[] = [
                'key' => $key,
                'value' => (string) ($settings[$key] ?? ''),
                'source' => (string) ($sources[$key] ?? 'CONFIG'),
                'type' => 'string',
            ];
        }

        return $items;
    }

    /** @return array<int, array{key: string, value: string}> */
    private function jsonUpdated(string $siteName, string $defaultLocale, string $theme, string $apiTokenIssueMode): array
    {
        return [
            ['key' => 'site_name', 'value' => $siteName],
            ['key' => 'default_locale', 'value' => $defaultLocale],
            ['key' => 'theme', 'value' => $theme],
            ['key' => 'api_token_issue_mode', 'value' => $apiTokenIssueMode],
        ];
    }

    private function jsonValidationFields(array $errors): array
    {
        $fields = [];
        foreach ($errors as $field) {
            if (!is_string($field) || $field === '') {
                continue;
            }
            $fields[$field] = ['invalid'];
        }

        return $fields;
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

    private function canManage(Request $request): bool
    {
        return $this->hasPermission($request, 'admin.settings.manage');
    }

    private function hasPermission(Request $request, string $permission): bool
    {
        if ($this->db === null || !$this->db->healthCheck()) {
            return false;
        }

        $userId = $this->currentUserId($request);
        if ($userId === null) {
            return false;
        }

        try {
            $rbac = new RbacRepository($this->db->pdo());
            return $rbac->userHasPermission($userId, $permission);
        } catch (Throwable) {
            return false;
        }
    }

    private function forbidden(Request $request, string $route): Response
    {
        if ($request->isHtmx() || $request->wantsJson()) {
            return ContractResponse::error('forbidden', ['route' => $route], 403);
        }

        return $this->view->render('pages/403.html', [], 403, [], [
            'theme' => 'admin',
        ]);
    }
}
