<?php
declare(strict_types=1);

namespace Laas\Modules\Admin\Controller;

use Laas\Database\DatabaseManager;
use Laas\Database\Repositories\SettingsRepository;
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
        $appConfig = $this->loadAppConfig();
        $locales = $this->normalizeList($appConfig['locales'] ?? []);
        $themes = $this->discoverThemes();

        $defaults = [
            'site_name' => 'LAAS CMS',
            'default_locale' => (string) ($appConfig['default_locale'] ?? 'en'),
            'theme' => (string) ($appConfig['theme'] ?? 'default'),
        ];

        $settings = $defaults;
        $sources = [
            'site_name' => 'CONFIG',
            'default_locale' => 'CONFIG',
            'theme' => 'CONFIG',
        ];
        $repo = $this->getRepository();
        if ($repo !== null) {
            $settings['site_name'] = (string) $repo->get('site_name', $defaults['site_name']);
            $settings['default_locale'] = (string) $repo->get('default_locale', $defaults['default_locale']);
            $settings['theme'] = (string) $repo->get('theme', $defaults['theme']);
            $sources['site_name'] = $repo->has('site_name') ? 'DB' : 'CONFIG';
            $sources['default_locale'] = $repo->has('default_locale') ? 'DB' : 'CONFIG';
            $sources['theme'] = $repo->has('theme') ? 'DB' : 'CONFIG';
        }

        if (!in_array($settings['default_locale'], $locales, true)) {
            $settings['default_locale'] = $defaults['default_locale'];
        }
        if (!in_array($settings['theme'], $themes, true)) {
            $settings['theme'] = $defaults['theme'];
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
        $appConfig = $this->loadAppConfig();
        $locales = $this->normalizeList($appConfig['locales'] ?? []);
        $themes = $this->discoverThemes();

        $siteName = trim((string) ($request->post('site_name') ?? ''));
        $defaultLocale = (string) ($request->post('default_locale') ?? '');
        $theme = (string) ($request->post('theme') ?? '');

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

        $repo = $this->getRepository();
        if ($repo === null) {
            return $this->saveErrorResponse($request, $siteName, $defaultLocale, $theme, $locales, $themes, 503);
        }

        if ($errors !== []) {
            return $this->saveErrorResponse($request, $siteName, $defaultLocale, $theme, $locales, $themes, 422);
        }

        $repo->set('site_name', $siteName, 'string');
        $repo->set('default_locale', $defaultLocale, 'string');
        $repo->set('theme', $theme, 'string');
        (new AuditLogger($this->db))->log(
            'settings.update',
            'setting',
            null,
            [
                'site_name' => $siteName,
                'default_locale' => $defaultLocale,
                'theme' => $theme,
            ],
            $this->currentUserId(),
            $request->ip()
        );

        if ($request->isHtmx()) {
            return $this->renderFormPartial($siteName, $defaultLocale, $theme, $locales, $themes, true, false, 200, [
                'site_name' => 'DB',
                'default_locale' => 'DB',
                'theme' => 'DB',
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
        array $locales,
        array $themes,
        int $status
    ): Response {
        $sources = [
            'site_name' => 'DB',
            'default_locale' => 'DB',
            'theme' => 'DB',
        ];
        $repo = $this->getRepository();
        if ($repo !== null) {
            $sources['site_name'] = $repo->has('site_name') ? 'DB' : 'CONFIG';
            $sources['default_locale'] = $repo->has('default_locale') ? 'DB' : 'CONFIG';
            $sources['theme'] = $repo->has('theme') ? 'DB' : 'CONFIG';
        }

        if ($request->isHtmx()) {
            return $this->renderFormPartial($siteName, $defaultLocale, $theme, $locales, $themes, false, true, $status, $sources);
        }

        return new Response('', 302, [
            'Location' => '/admin/settings?error=1',
        ]);
    }

    private function renderFormPartial(
        string $siteName,
        string $defaultLocale,
        string $theme,
        array $locales,
        array $themes,
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
            ],
            'source' => $sources,
            'localesOptions' => $this->buildOptions($locales, $defaultLocale),
            'themesOptions' => $this->buildOptions($themes, $theme),
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

    private function currentUserId(): ?int
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return null;
        }

        $raw = $_SESSION['user_id'] ?? null;
        if (is_int($raw)) {
            return $raw;
        }

        if (is_string($raw) && ctype_digit($raw)) {
            return (int) $raw;
        }

        return null;
    }
}
