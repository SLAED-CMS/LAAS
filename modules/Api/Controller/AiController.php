<?php
declare(strict_types=1);

namespace Laas\Modules\Api\Controller;

use Laas\Api\ApiResponse;
use Laas\Ai\Context\AiContextBuilder;
use Laas\Ai\Context\Redactor;
use Laas\Ai\Provider\AiProviderInterface;
use Laas\Ai\Provider\LocalDemoProvider;
use Laas\Ai\Provider\RemoteHttpProvider;
use Laas\Database\DatabaseManager;
use Laas\Http\ErrorCode;
use Laas\Http\Request;
use Laas\Http\Response;
use Laas\Support\AuditLogger;
use Laas\Support\SafeHttpClient;
use Laas\Support\UrlPolicy;
use DomainException;
use RuntimeException;

final class AiController
{
    public function __construct(private ?DatabaseManager $db = null)
    {
    }

    public function propose(Request $request): Response
    {
        $user = $request->getAttribute('api.user');
        if (!is_array($user)) {
            return ApiResponse::error(ErrorCode::AUTH_REQUIRED, 'Unauthorized', [], 401);
        }

        $securityConfig = $this->securityConfig();
        $provider = $this->resolveProvider($securityConfig, $request);
        $providerName = (string) ($securityConfig['ai_provider'] ?? 'local_demo');

        $builder = new AiContextBuilder();
        $context = $builder->build($request);
        $prompt = (string) ($context['prompt'] ?? '');

        try {
            $result = $provider->propose($context);
        } catch (DomainException $e) {
            $code = $e->getMessage();
            if ($code === 'remote_ai_disabled') {
                return ApiResponse::error(ErrorCode::SERVICE_UNAVAILABLE, 'Service Unavailable', [
                    'reason' => 'remote_ai_disabled',
                ], 503);
            }
            if ($code === 'remote_ai_forbidden') {
                return ApiResponse::error(ErrorCode::RBAC_DENIED, 'Forbidden', [
                    'reason' => 'remote_ai_forbidden',
                ], 403);
            }
            if ($code === 'remote_ai_request_too_large') {
                return ApiResponse::error(ErrorCode::INVALID_REQUEST, 'Request too large', [
                    'reason' => 'remote_ai_request_too_large',
                ], 413);
            }
            if ($code === 'remote_ai_response_too_large') {
                return ApiResponse::error(ErrorCode::SERVICE_UNAVAILABLE, 'Service Unavailable', [
                    'reason' => 'remote_ai_response_too_large',
                ], 502);
            }
            return ApiResponse::error(ErrorCode::SERVICE_UNAVAILABLE, 'Service Unavailable', [
                'reason' => 'remote_ai_failed',
            ], 502);
        } catch (RuntimeException) {
            return ApiResponse::error(ErrorCode::SERVICE_UNAVAILABLE, 'Service Unavailable', [
                'reason' => 'remote_ai_failed',
            ], 502);
        }

        (new AuditLogger($this->db, $request->session()))->log(
            'ai.propose_called',
            'ai',
            null,
            [
                'provider' => $providerName,
                'prompt_len' => strlen($prompt),
                'path' => $request->getPath(),
            ],
            (int) ($user['id'] ?? 0),
            $request->ip()
        );

        return ApiResponse::ok([
            'proposal' => $result['proposal'] ?? [],
            'plan' => $result['plan'] ?? [],
        ], [], 200, [
            'Cache-Control' => 'no-store',
        ]);
    }

    private function resolveProvider(array $securityConfig, Request $request): AiProviderInterface
    {
        $name = strtolower((string) ($securityConfig['ai_provider'] ?? 'local_demo'));
        if ($name === 'remote_http') {
            $allowlist = is_array($securityConfig['ai_remote_allowlist'] ?? null)
                ? $securityConfig['ai_remote_allowlist']
                : [];
            $policy = new UrlPolicy(['http', 'https'], $this->hostsFromAllowlist($allowlist));
            $client = new SafeHttpClient($policy, 8, 3, 0, 300000);
            return new RemoteHttpProvider($client, new Redactor(), $securityConfig, new AuditLogger($this->db, $request->session()));
        }

        return new LocalDemoProvider();
    }

    private function securityConfig(): array
    {
        $root = dirname(__DIR__, 3);
        $path = $root . '/config/security.php';
        $config = is_file($path) ? require $path : [];
        if (!is_array($config)) {
            $config = [];
        }

        $localPath = $root . '/config/security.local.php';
        if (is_file($localPath)) {
            $localConfig = require $localPath;
            if (is_array($localConfig)) {
                $config = array_replace($config, $localConfig);
            }
        }

        return $config;
    }

    /**
     * @param array<int, mixed> $allowlist
     * @return array<int, string>
     */
    private function hostsFromAllowlist(array $allowlist): array
    {
        $hosts = [];
        foreach ($allowlist as $entry) {
            if (!is_string($entry) || $entry === '') {
                continue;
            }
            $parts = parse_url($entry);
            if (!is_array($parts)) {
                continue;
            }
            $host = strtolower((string) ($parts['host'] ?? ''));
            if ($host !== '') {
                $hosts[] = $host;
            }
        }

        return array_values(array_unique($hosts));
    }
}
