<?php
declare(strict_types=1);

namespace Laas\Modules\System\Controller;

use Laas\Core\Container\Container;
use Laas\Domain\Security\SecurityReportsWriteServiceInterface;
use Laas\DevTools\DevToolsContext;
use Laas\Http\Request;
use Laas\Http\Response;
use Laas\I18n\Translator;
use Laas\Support\LoggerFactory;
use Laas\Support\RequestScope;
use Laas\View\View;
use Psr\Log\LoggerInterface;

final class CspReportController
{
    private Translator $translator;
    private LoggerInterface $logger;

    public function __construct(
        private ?View $view = null,
        private ?SecurityReportsWriteServiceInterface $reportsService = null,
        private ?Container $container = null,
        ?Translator $translator = null,
        ?LoggerInterface $logger = null
    )
    {
        $rootPath = dirname(__DIR__, 3);
        $appConfig = $this->loadConfig($rootPath . '/config/app.php');

        $locale = (string) ($appConfig['default_locale'] ?? 'en');
        $theme = (string) ($appConfig['theme'] ?? 'default');
        $this->translator = $translator ?? new Translator($rootPath, $theme, $locale);
        $this->logger = $logger ?? (new LoggerFactory($rootPath))->create($appConfig);
    }

    public function report(Request $request): Response
    {
        $report = $this->extractReport($request->getBody());
        if ($report === null) {
            return new Response('', 204);
        }

        $documentUri = $this->sanitizeUri($this->readReportValue($report, ['document-uri', 'document_uri']));
        $violated = $this->sanitizeText($this->readReportValue($report, ['violated-directive', 'violated_directive', 'effective-directive', 'effective_directive']));
        $blocked = $this->sanitizeUri($this->readReportValue($report, ['blocked-uri', 'blocked_uri']));
        $userAgent = $this->sanitizeText($request->getHeader('user-agent') ?? '', 255);
        $occurredAt = gmdate('c');
        $ip = $request->ip();
        $requestId = $this->resolveRequestId();

        $service = $this->reportsService();
        if ($service !== null) {
            try {
                $service->insert([
                    'type' => 'csp',
                    'created_at' => date('Y-m-d H:i:s'),
                    'document_uri' => $documentUri,
                    'violated_directive' => $violated,
                    'blocked_uri' => $blocked,
                    'user_agent' => $userAgent,
                    'ip' => $ip,
                    'request_id' => $requestId,
                ]);
            } catch (\Throwable) {
                // best-effort storage
            }
        }

        $this->logger->info($this->translator->trans('security.csp_report_received'), [
            'document_uri' => $documentUri,
            'violated_directive' => $violated,
            'blocked_uri' => $blocked,
            'user_agent' => $userAgent,
            'occurred_at' => $occurredAt,
            'ip' => $ip,
            'request_id' => $requestId,
        ]);

        return new Response('', 204);
    }

    private function extractReport(string $raw): ?array
    {
        $payload = json_decode($raw, true);
        if (!is_array($payload)) {
            return null;
        }

        if (isset($payload['csp-report']) && is_array($payload['csp-report'])) {
            return $payload['csp-report'];
        }

        if (isset($payload['csp_report']) && is_array($payload['csp_report'])) {
            return $payload['csp_report'];
        }

        return $payload;
    }

    private function readReportValue(array $report, array $keys): string
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $report)) {
                $value = $report[$key];
                if (is_string($value)) {
                    return $value;
                }
            }
        }

        return '';
    }

    private function sanitizeUri(string $value): string
    {
        $value = $this->stripControlChars($value);
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        if (str_starts_with($value, '/')) {
            $path = $this->stripQueryFragment($value);
            return $this->truncate($path, 255);
        }

        $parts = parse_url($value);
        if (is_array($parts)) {
            $scheme = strtolower((string) ($parts['scheme'] ?? ''));
            if ($scheme === 'http' || $scheme === 'https') {
                $host = (string) ($parts['host'] ?? '');
                if ($host !== '') {
                    $port = isset($parts['port']) ? ':' . (int) $parts['port'] : '';
                    $path = (string) ($parts['path'] ?? '/');
                    if ($path === '') {
                        $path = '/';
                    }
                    return $this->truncate($scheme . '://' . $host . $port . $path, 255);
                }
            }
        }

        return $this->truncate($value, 255);
    }

    private function sanitizeText(string $value, int $max = 255): string
    {
        $value = $this->stripControlChars($value);
        $value = trim($value);
        return $value === '' ? '' : $this->truncate($value, $max);
    }

    private function stripControlChars(string $value): string
    {
        return preg_replace('/[\x00-\x1F\x7F]/', '', $value) ?? '';
    }

    private function stripQueryFragment(string $value): string
    {
        $value = explode('#', $value, 2)[0];
        return explode('?', $value, 2)[0];
    }

    private function truncate(string $value, int $max): string
    {
        if ($max <= 0 || $value === '') {
            return '';
        }

        if (function_exists('mb_strlen') && function_exists('mb_substr')) {
            if (mb_strlen($value, 'UTF-8') <= $max) {
                return $value;
            }
            return mb_substr($value, 0, $max, 'UTF-8');
        }

        return strlen($value) <= $max ? $value : substr($value, 0, $max);
    }

    private function resolveRequestId(): ?string
    {
        $context = RequestScope::get('devtools.context');
        if ($context instanceof DevToolsContext) {
            $id = $context->getRequestId();
            return $id !== '' ? $id : null;
        }

        return null;
    }

    private function loadConfig(string $path): array
    {
        if (!is_file($path)) {
            return [];
        }
        $config = require $path;
        return is_array($config) ? $config : [];
    }

    private function reportsService(): ?SecurityReportsWriteServiceInterface
    {
        if ($this->reportsService !== null) {
            return $this->reportsService;
        }

        if ($this->container !== null) {
            try {
                $service = $this->container->get(SecurityReportsWriteServiceInterface::class);
                if ($service instanceof SecurityReportsWriteServiceInterface) {
                    $this->reportsService = $service;
                    return $this->reportsService;
                }
            } catch (\Throwable) {
                return null;
            }
        }

        return null;
    }
}
