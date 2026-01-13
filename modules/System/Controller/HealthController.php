<?php
declare(strict_types=1);

namespace Laas\Modules\System\Controller;

use Laas\Database\DatabaseManager;
use Laas\Http\Request;
use Laas\Http\Response;
use Laas\I18n\Translator;
use Laas\Modules\Media\Service\StorageService;
use Laas\Ops\Checks\SecurityHeadersCheck;
use Laas\Support\ConfigSanityChecker;
use Laas\Support\HealthService;
use Laas\Support\HealthStatusTracker;
use Laas\Support\LoggerFactory;
use Laas\Support\SessionConfigValidator;

final class HealthController
{
    private HealthService $healthService;
    private Translator $translator;
    private ?HealthStatusTracker $tracker = null;
    private array $securityConfig = [];

    public function __construct(?HealthService $healthService = null, ?Translator $translator = null)
    {
        $rootPath = dirname(__DIR__, 3);
        $appConfig = $this->loadConfig($rootPath . '/config/app.php');
        $mediaConfig = $this->loadConfig($rootPath . '/config/media.php');
        $storageConfig = $this->loadConfig($rootPath . '/config/storage.php');
        $securityConfig = $this->loadConfig($rootPath . '/config/security.php');
        $this->securityConfig = $securityConfig;

        $locale = (string) ($appConfig['default_locale'] ?? 'en');
        $theme = (string) ($appConfig['theme'] ?? 'default');
        $this->translator = $translator ?? new Translator($rootPath, $theme, $locale);

        if ($healthService !== null) {
            $this->healthService = $healthService;
            return;
        }

        $dbConfig = $this->loadConfig($rootPath . '/config/database.php');
        $db = new DatabaseManager($dbConfig);
        $storage = new StorageService($rootPath);
        $checker = new ConfigSanityChecker();
        $config = [
            'media' => $mediaConfig,
            'storage' => $storageConfig,
            'session' => is_array($securityConfig['session'] ?? null) ? $securityConfig['session'] : [],
        ];
        $writeCheck = (bool) ($appConfig['health_write_check'] ?? false);

        $this->healthService = new HealthService(
            $rootPath,
            static fn (): bool => $db->healthCheck(),
            $storage,
            $checker,
            $config,
            $writeCheck,
            new SessionConfigValidator()
        );

        $logger = (new LoggerFactory($rootPath))->create($appConfig);
        $this->tracker = new HealthStatusTracker($rootPath, $logger);
    }

    public function index(Request $request): Response
    {
        $result = $this->healthService->check();
        $ok = (bool) ($result['ok'] ?? false);
        $securityCheck = new SecurityHeadersCheck($this->securityConfig, $request->isHttps());
        $headersResult = $securityCheck->run();
        if ($headersResult['code'] === 1) {
            $ok = false;
        }
        $messageKey = $ok ? 'system.health.ok' : 'system.health.degraded';
        if ($this->tracker !== null) {
            $this->tracker->logHealthTransition($ok);
        }

        $checks = $result['checks'] ?? [];
        $checks['security_headers'] = $headersResult['code'] !== 1;
        $warnings = $result['warnings'] ?? [];
        if ($headersResult['code'] !== 0) {
            $warnings[] = $this->translator->trans('security.headers_invalid');
        }
        $payload = [
            'status' => $ok ? 'ok' : 'degraded',
            'message' => $this->translator->trans($messageKey),
            'checks' => $this->formatChecks($checks),
            'timestamp' => gmdate('c'),
        ];
        if (is_array($warnings) && $warnings !== []) {
            $payload['warnings'] = array_values(array_filter(array_map('strval', $warnings)));
        }

        return Response::json($payload, $ok ? 200 : 503);
    }

    private function loadConfig(string $path): array
    {
        if (!is_file($path)) {
            return [];
        }
        $config = require $path;
        return is_array($config) ? $config : [];
    }

    /** @param array<string, bool> $checks */
    private function formatChecks(array $checks): array
    {
        $result = [];
        foreach ($checks as $key => $value) {
            $result[$key] = $value ? 'ok' : 'fail';
        }
        return $result;
    }
}
