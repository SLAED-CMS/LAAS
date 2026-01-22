<?php

declare(strict_types=1);

namespace Laas\Modules\Admin\Controller;

use Laas\Core\Container\Container;
use Laas\Core\FeatureFlagsInterface;
use Laas\Domain\Rbac\RbacServiceInterface;
use Laas\Http\ErrorResponse;
use Laas\Http\Request;
use Laas\Http\Response;
use Laas\Theme\ThemeValidationResult;
use Laas\Theme\ThemeValidator;
use Laas\View\Theme\ThemeManager;
use Laas\View\View;
use Throwable;

final class ThemesController
{
    private ?RbacServiceInterface $rbacService = null;
    private ?ThemeValidator $themeValidator = null;

    public function __construct(
        private View $view,
        private ?ThemeManager $themeManager = null,
        private ?Container $container = null
    ) {
    }

    public function index(Request $request): Response
    {
        if (!$this->isDevtoolsEnabled(FeatureFlagsInterface::DEVTOOLS_THEME_INSPECTOR)) {
            return $this->notFound($request, 'admin.themes.disabled');
        }

        if (!$this->hasAccess($request)) {
            return $this->forbidden($request, 'admin.themes.index');
        }

        $themeName = $this->view->getThemeName();
        $manager = $this->resolveThemeManager($themeName);
        $validation = $this->validateTheme($themeName);
        $snapshot = $this->snapshotInfo($themeName);

        $response = $this->view->render('pages/themes.html', [
            'theme_name' => $themeName,
            'theme_api' => $manager->getThemeApi($themeName),
            'theme_version' => $manager->getThemeVersion($themeName),
            'theme_capabilities' => $manager->getCapabilities($themeName),
            'theme_provides' => $manager->getProvides($themeName),
            'theme_snapshot_hash' => $snapshot['snapshot_hash'],
            'theme_current_hash' => $snapshot['current_hash'],
            'theme_manifest_path' => $snapshot['manifest_path'],
            'theme_validation' => $this->validationPayload($validation),
            'theme_validation_ok' => !$validation->hasViolations() && !$validation->hasWarnings(),
            'theme_debug' => $this->isDebug(),
        ], 200, [], [
            'theme' => 'admin',
        ]);

        return $this->withNoStore($response);
    }

    public function validate(Request $request): Response
    {
        if (!$this->isDevtoolsEnabled(FeatureFlagsInterface::DEVTOOLS_THEME_INSPECTOR)) {
            return $this->notFound($request, 'admin.themes.disabled');
        }

        if (!$this->hasAccess($request)) {
            return $this->forbidden($request, 'admin.themes.validate');
        }
        if (!$this->isDebug()) {
            return $this->forbidden($request, 'admin.themes.validate');
        }

        $themeName = $this->view->getThemeName();
        $validation = $this->validateTheme($themeName);
        $payload = $this->validationPayload($validation);
        $status = $validation->hasViolations() ? 422 : 200;

        if ($request->wantsJson()) {
            $response = Response::json([
                'validation' => $payload,
            ], $status);
            return $this->withNoStore($response);
        }

        $response = $this->view->render('partials/theme_validation.html', [
            'theme_validation' => $payload,
            'theme_validation_ok' => !$validation->hasViolations() && !$validation->hasWarnings(),
        ], $status, [], [
            'theme' => 'admin',
            'render_partial' => true,
        ]);

        if ($validation->hasViolations()) {
            return $this->withNoStore($response->withToastDanger('theme.validate.failed', 'Theme validation failed.'));
        }
        if ($validation->hasWarnings()) {
            return $this->withNoStore($response->withToastWarning('theme.validate.warn', 'Theme validation warnings.'));
        }
        return $this->withNoStore($response->withToastSuccess('theme.validate.ok', 'Theme validation OK.'));
    }

    private function validateTheme(string $themeName): ThemeValidationResult
    {
        try {
            $validator = $this->resolveThemeValidator();
            return $validator->validateTheme($themeName);
        } catch (Throwable) {
            $result = new ThemeValidationResult($themeName);
            $result->addViolation('theme_validate_failed', $themeName, 'Theme validation failed');
            return $result;
        }
    }

    /**
     * @return array{snapshot_hash: string, current_hash: string, manifest_path: string}
     */
    private function snapshotInfo(string $themeName): array
    {
        $root = $this->rootPath();
        $manifestPath = $root . '/themes/' . $themeName . '/theme.json';
        $currentHash = '';
        if (is_file($manifestPath)) {
            $hash = hash_file('sha256', $manifestPath);
            $currentHash = is_string($hash) ? $hash : '';
        }

        $snapshotHash = '';
        $snapshotPath = $root . '/config/theme_snapshot.php';
        if (is_file($snapshotPath)) {
            $snapshot = require $snapshotPath;
            if (is_array($snapshot)) {
                $themes = $snapshot['themes'] ?? [];
                $entry = $themes[$themeName] ?? null;
                if (is_array($entry)) {
                    $snapshotHash = (string) ($entry['sha256'] ?? '');
                } elseif (is_string($entry)) {
                    $snapshotHash = $entry;
                }
            }
        }

        return [
            'snapshot_hash' => $snapshotHash,
            'current_hash' => $currentHash,
            'manifest_path' => $this->relativePath($manifestPath),
        ];
    }

    /**
     * @return array{violations: array<int, array<string, string>>, warnings: array<int, array<string, string>>}
     */
    private function validationPayload(ThemeValidationResult $result): array
    {
        return [
            'violations' => $result->getViolations(),
            'warnings' => $result->getWarnings(),
        ];
    }

    private function hasAccess(Request $request): bool
    {
        $userId = $this->currentUserId($request);
        if ($userId === null) {
            return false;
        }

        $rbac = $this->rbacService();
        if ($rbac === null) {
            return false;
        }

        return $rbac->userHasPermission($userId, 'admin.access');
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

    private function resolveThemeManager(string $themeName): ThemeManager
    {
        if ($this->themeManager !== null) {
            return $this->themeManager;
        }

        if ($this->container !== null) {
            try {
                $manager = $this->container->get(ThemeManager::class);
                if ($manager instanceof ThemeManager) {
                    $this->themeManager = $manager;
                    return $this->themeManager;
                }
            } catch (Throwable) {
                return new ThemeManager($this->themesRoot(), $themeName, null);
            }
        }

        return new ThemeManager($this->themesRoot(), $themeName, null);
    }

    private function resolveThemeValidator(): ThemeValidator
    {
        if ($this->themeValidator !== null) {
            return $this->themeValidator;
        }

        if ($this->container !== null) {
            try {
                $validator = $this->container->get(ThemeValidator::class);
                if ($validator instanceof ThemeValidator) {
                    $this->themeValidator = $validator;
                    return $this->themeValidator;
                }
            } catch (Throwable) {
                return new ThemeValidator($this->themesRoot());
            }
        }

        return new ThemeValidator($this->themesRoot());
    }

    private function forbidden(Request $request, string $route): Response
    {
        return $this->withNoStore(
            ErrorResponse::respondForRequest($request, 'forbidden', [], 403, [], $route)
        );
    }

    private function featureFlags(): ?FeatureFlagsInterface
    {
        if ($this->container === null) {
            return null;
        }

        try {
            $flags = $this->container->get(FeatureFlagsInterface::class);
            return $flags instanceof FeatureFlagsInterface ? $flags : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function isDevtoolsEnabled(string $flag): bool
    {
        $flags = $this->featureFlags();
        if ($flags === null) {
            return false;
        }

        return $flags->isEnabled($flag);
    }

    private function notFound(Request $request, string $route): Response
    {
        return $this->withNoStore(
            ErrorResponse::respondForRequest($request, 'not_found', [], 404, [], $route)
        );
    }

    private function withNoStore(Response $response): Response
    {
        return $response->withHeader('Cache-Control', 'no-store');
    }

    private function isDebug(): bool
    {
        $config = $this->appConfig();
        return (bool) ($config['debug'] ?? false);
    }

    private function appConfig(): array
    {
        $path = $this->rootPath() . '/config/app.php';
        if (!is_file($path)) {
            return [];
        }
        $config = require $path;
        return is_array($config) ? $config : [];
    }

    private function rootPath(): string
    {
        return dirname(__DIR__, 3);
    }

    private function themesRoot(): string
    {
        return $this->rootPath() . '/themes';
    }

    private function relativePath(string $path): string
    {
        $root = $this->rootPath();
        $normalized = str_replace('\\', '/', $path);
        $root = str_replace('\\', '/', $root);
        if (str_starts_with($normalized, $root . '/')) {
            return substr($normalized, strlen($root) + 1);
        }
        return $normalized;
    }
}
