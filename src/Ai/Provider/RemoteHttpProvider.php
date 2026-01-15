<?php
declare(strict_types=1);

namespace Laas\Ai\Provider;

use DomainException;
use Laas\Ai\Context\Redactor;
use Laas\Support\AuditLogger;
use Laas\Support\SafeHttpClient;
use Laas\Support\UrlPolicy;
use RuntimeException;
use Throwable;

final class RemoteHttpProvider implements AiProviderInterface
{
    public function __construct(
        private SafeHttpClient $httpClient,
        private Redactor $redactor,
        private array $config,
        private ?AuditLogger $auditLogger = null
    ) {
    }

    public function propose(array $input): array
    {
        if (empty($this->config['ai_remote_enabled'])) {
            throw new DomainException('remote_ai_disabled');
        }

        $allowlist = $this->normalizeAllowlist($this->config['ai_remote_allowlist'] ?? []);
        $baseUrl = $this->resolveBaseUrl($allowlist);
        $endpoint = (string) ($this->config['ai_remote_endpoint'] ?? '/v1/propose');
        if ($endpoint === '' || $endpoint[0] !== '/') {
            $endpoint = '/' . ltrim($endpoint, '/');
        }
        $url = rtrim($baseUrl, '/') . $endpoint;

        $prompt = (string) ($input['prompt'] ?? '');
        $prompt = $this->redactor->redact($prompt);

        $payload = [
            'prompt' => $prompt,
            'context' => [
                'route' => (string) ($input['route'] ?? ''),
                'user_id' => $input['user_id'] ?? null,
                'timestamp' => (string) ($input['timestamp'] ?? gmdate(DATE_ATOM)),
            ],
            'capabilities' => is_array($input['capabilities'] ?? null) ? $input['capabilities'] : ['sandbox' => true],
        ];

        $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            throw new DomainException('remote_ai_request_invalid');
        }

        $maxRequestBytes = (int) ($this->config['ai_remote_max_request_bytes'] ?? 200000);
        if ($maxRequestBytes > 0 && strlen($json) > $maxRequestBytes) {
            throw new DomainException('remote_ai_request_too_large');
        }

        $headers = [
            'Content-Type' => 'application/json',
        ];
        $authHeader = trim((string) ($this->config['ai_remote_auth_header'] ?? ''));
        if ($authHeader !== '') {
            $headers['Authorization'] = $authHeader;
        }

        $timeoutMs = (int) ($this->config['ai_remote_timeout_ms'] ?? 8000);
        $timeoutSeconds = max(1, (int) ceil($timeoutMs / 1000));
        $maxResponseBytes = (int) ($this->config['ai_remote_max_response_bytes'] ?? 300000);

        $status = 0;
        $respBytes = 0;
        $started = microtime(true);
        try {
            $response = $this->httpClient->request('POST', $url, $headers, $json, [
                'timeout' => $timeoutSeconds,
                'connect_timeout' => min(3, $timeoutSeconds),
                'max_redirects' => 0,
                'max_bytes' => $maxResponseBytes,
            ]);
            $status = (int) ($response['status'] ?? 0);
            $body = (string) ($response['body'] ?? '');
            $respBytes = strlen($body);

            if ($status < 200 || $status >= 300) {
                throw new DomainException('remote_ai_failed');
            }

            $decoded = json_decode($body, true);
            if (!is_array($decoded)) {
                throw new DomainException('remote_ai_response_invalid');
            }

            $proposal = $decoded['proposal'] ?? null;
            $plan = $decoded['plan'] ?? null;
            if (!is_array($proposal) || !is_array($plan)) {
                throw new DomainException('remote_ai_response_invalid');
            }

            return [
                'proposal' => $proposal,
                'plan' => $plan,
            ];
        } catch (RuntimeException $e) {
            if ($e->getMessage() === 'http_response_too_large') {
                throw new DomainException('remote_ai_response_too_large');
            }
            throw new DomainException('remote_ai_failed');
        } finally {
            $durationMs = (microtime(true) - $started) * 1000;
            $this->audit('ai.remote_called', [
                'provider_host' => $this->originString($baseUrl),
                'status' => $status,
                'req_bytes' => strlen($json),
                'resp_bytes' => $respBytes,
                'duration_ms' => (int) $durationMs,
                'user_id' => $this->normalizeUserId($input['user_id'] ?? null),
            ]);
        }
    }

    /**
     * @param array<int, string> $allowlist
     */
    private function resolveBaseUrl(array $allowlist): string
    {
        $base = trim((string) ($this->config['ai_remote_base'] ?? ''));
        if ($base !== '') {
            if (!$this->isAllowlisted($base, $allowlist)) {
                throw new DomainException('remote_ai_forbidden');
            }
            return $base;
        }

        if ($allowlist === []) {
            throw new DomainException('remote_ai_forbidden');
        }

        $candidate = $allowlist[0];
        if (!$this->isAllowlisted($candidate, $allowlist)) {
            throw new DomainException('remote_ai_forbidden');
        }

        return $candidate;
    }

    /**
     * @param array<int, string> $allowlist
     */
    private function isAllowlisted(string $baseUrl, array $allowlist): bool
    {
        $baseOrigin = $this->originString($baseUrl);
        if ($baseOrigin === '') {
            return false;
        }

        foreach ($allowlist as $entry) {
            if ($this->originString($entry) === $baseOrigin) {
                return true;
            }
        }

        return false;
    }

    private function originString(string $url): string
    {
        $parts = parse_url($url);
        if (!is_array($parts)) {
            return '';
        }
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));
        if ($scheme === '' || $host === '') {
            return '';
        }
        $port = isset($parts['port']) ? (int) $parts['port'] : ($scheme === 'https' ? 443 : 80);
        return $scheme . '://' . $host . ':' . $port;
    }

    /**
     * @param array<int, mixed> $allowlist
     * @return array<int, string>
     */
    private function normalizeAllowlist(array $allowlist): array
    {
        $out = [];
        foreach ($allowlist as $item) {
            if (!is_string($item)) {
                continue;
            }
            $item = trim($item);
            if ($item === '') {
                continue;
            }
            $out[] = $item;
        }

        return $out;
    }

    private function normalizeUserId(mixed $value): ?int
    {
        if (is_int($value) && $value > 0) {
            return $value;
        }
        if (is_string($value) && ctype_digit($value)) {
            return (int) $value;
        }

        return null;
    }

    /** @param array<string, mixed> $context */
    private function audit(string $action, array $context): void
    {
        if ($this->auditLogger === null) {
            return;
        }

        $userId = $context['user_id'] ?? null;
        unset($context['user_id']);
        $this->auditLogger->log(
            $action,
            'ai',
            null,
            $context,
            $this->normalizeUserId($userId),
            null
        );
    }
}
