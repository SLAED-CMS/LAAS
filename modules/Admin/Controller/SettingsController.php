<?php
declare(strict_types=1);

namespace Laas\Modules\Admin\Controller;

use Laas\Core\Container\Container;
use Laas\Domain\Rbac\RbacServiceInterface;
use Laas\Domain\Settings\SettingsServiceInterface;
use Laas\Http\Contract\ContractResponse;
use Laas\Http\ErrorResponse;
use Laas\Http\Request;
use Laas\Http\Response;
use Laas\Support\Audit;
use Laas\View\SanitizedHtml;
use Laas\View\View;
use Throwable;

final class SettingsController
{
    public function __construct(
        private View $view,
        private ?SettingsServiceInterface $settingsService = null,
        private ?Container $container = null
    ) {
    }

    public function index(Request $request): Response
    {
        if (!$this->canManage($request)) {
            return $this->forbidden($request, 'admin.settings.index');
        }

        $service = $this->service();
        if ($service === null) {
            return $this->errorResponse($request, 'db_unavailable', 503);
        }

        $defaults = $service->defaultSettings();
        $locales = $service->availableLocales();
        $themes = $service->availableThemes();
        $tokenIssueModes = $this->apiTokenIssueModes();

        $payload = $service->settingsWithSources();
        $settings = $payload['settings'];
        $sources = $payload['sources'];

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

        $service = $this->service();
        if ($service === null) {
            return $this->errorResponse($request, 'db_unavailable', 503);
        }

        $defaults = $service->defaultSettings();
        $locales = $service->availableLocales();
        $themes = $service->availableThemes();
        $tokenIssueModes = $this->apiTokenIssueModes();

        $siteName = trim((string) ($request->post('site_name') ?? ''));
        $defaultLocale = (string) ($request->post('default_locale') ?? '');
        $theme = (string) ($request->post('theme') ?? '');
        $apiTokenIssueMode = (string) ($request->post('api_token_issue_mode') ?? ($defaults['api_token_issue_mode'] ?? 'admin'));

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

        if ($errors !== []) {
            return $this->saveErrorResponse($request, $siteName, $defaultLocale, $theme, $apiTokenIssueMode, $locales, $themes, $tokenIssueModes, 422, $errors);
        }

        try {
            $service->setMany([
                'site_name' => $siteName,
                'default_locale' => $defaultLocale,
                'theme' => $theme,
                'api_token_issue_mode' => $apiTokenIssueMode,
            ]);
        } catch (Throwable) {
            return $this->saveErrorResponse($request, $siteName, $defaultLocale, $theme, $apiTokenIssueMode, $locales, $themes, $tokenIssueModes, 503, $errors);
        }

        Audit::log('settings.save', 'setting', null, [
            'actor_user_id' => $this->currentUserId($request),
            'site_name' => $siteName,
            'default_locale' => $defaultLocale,
            'theme' => $theme,
            'api_token_issue_mode' => $apiTokenIssueMode,
        ]);

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
            $response = $this->renderFormPartial($siteName, $defaultLocale, $theme, $apiTokenIssueMode, $locales, $themes, $tokenIssueModes, true, false, 200, [
                'site_name' => 'DB',
                'default_locale' => 'DB',
                'theme' => 'DB',
                'api_token_issue_mode' => 'DB',
            ]);
            return $this->withSuccessTrigger($response, 'admin.settings.saved');
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
        $service = $this->service();
        if ($service !== null) {
            $sources = $service->sources(['site_name', 'default_locale', 'theme', 'api_token_issue_mode']);
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

    private function buildOptions(array $values, string $selected): array
    {
        $options = [];
        foreach ($values as $value) {
            $options[] = [
                'value' => $value,
                'label' => $value,
                'selected_attr' => SanitizedHtml::fromSanitized($value === $selected ? 'selected' : ''),
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
                'selected_attr' => SanitizedHtml::fromSanitized($value === $selected ? 'selected' : ''),
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
        $userId = $this->currentUserId($request);
        if ($userId === null) {
            return false;
        }

        $rbac = $this->rbac();
        if ($rbac === null) {
            return false;
        }

        return $rbac->userHasPermission($userId, $permission);
    }

    private function forbidden(Request $request, string $route): Response
    {
        if ($request->wantsJson()) {
            return ContractResponse::error('forbidden', ['route' => $route], 403);
        }

        return ErrorResponse::respondForRequest($request, 'forbidden', [], 403, [], $route);
    }

    private function withSuccessTrigger(Response $response, string $messageKey): Response
    {
        return $response->withToastSuccess($messageKey, $this->view->translate($messageKey));
    }

    private function service(): ?SettingsServiceInterface
    {
        if ($this->settingsService !== null) {
            return $this->settingsService;
        }

        if ($this->container !== null) {
            try {
                $service = $this->container->get(SettingsServiceInterface::class);
                if ($service instanceof SettingsServiceInterface) {
                    $this->settingsService = $service;
                    return $this->settingsService;
                }
            } catch (Throwable) {
                return null;
            }
        }

        return null;
    }

    private function rbac(): ?RbacServiceInterface
    {
        if ($this->container === null) {
            return null;
        }

        try {
            $service = $this->container->get(RbacServiceInterface::class);
            return $service instanceof RbacServiceInterface ? $service : null;
        } catch (Throwable) {
            return null;
        }
    }
}
