<?php
declare(strict_types=1);

namespace Laas\Modules\Api\Controller;

use Laas\Api\ApiResponse;
use Laas\Ai\Context\AiContextBuilder;
use Laas\Ai\Context\Redactor;
use Laas\Ai\Provider\AiProviderInterface;
use Laas\Ai\Provider\LocalDemoProvider;
use Laas\Ai\Provider\RemoteHttpProvider;
use Laas\Ai\Plan;
use Laas\Ai\PlanRunner;
use Laas\Ai\Tools\ToolRegistry;
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

        if ($this->isHtmx($request)) {
            return Response::html($this->renderProposeHtml($result), 200);
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

    public function tools(Request $request): Response
    {
        $user = $request->getAttribute('api.user');
        if (!is_array($user)) {
            return ApiResponse::error(ErrorCode::AUTH_REQUIRED, 'Unauthorized', [], 401);
        }

        $registry = new ToolRegistry($this->securityConfig());
        return ApiResponse::ok([
            'tools' => $registry->list(),
        ], [], 200, [
            'Cache-Control' => 'no-store',
        ]);
    }

    public function runTools(Request $request): Response
    {
        $user = $request->getAttribute('api.user');
        if (!is_array($user)) {
            return ApiResponse::error(ErrorCode::AUTH_REQUIRED, 'Unauthorized', [], 401);
        }

        $input = $this->readJson($request);
        $planInput = is_array($input['plan'] ?? null) ? $input['plan'] : [];
        $stepsInput = is_array($planInput['steps'] ?? null) ? $planInput['steps'] : [];

        $registry = new ToolRegistry($this->securityConfig());
        $tools = $registry->list();
        $allowMap = $this->buildAllowMap($tools);

        $steps = [];
        foreach ($stepsInput as $index => $step) {
            if (!is_array($step)) {
                return ApiResponse::error(ErrorCode::INVALID_REQUEST, 'Invalid request', [
                    'step' => $index,
                ], 400);
            }
            $command = (string) ($step['command'] ?? '');
            $args = $step['args'] ?? [];
            if ($command === '' || !is_array($args)) {
                return ApiResponse::error(ErrorCode::INVALID_REQUEST, 'Invalid request', [
                    'step' => $index,
                ], 400);
            }
            if (!isset($allowMap[$command])) {
                return ApiResponse::error(ErrorCode::RBAC_DENIED, 'Forbidden', [
                    'command' => $command,
                ], 403);
            }
            $allowedArgs = $allowMap[$command];
            foreach ($args as $arg) {
                if (!is_string($arg)) {
                    return ApiResponse::error(ErrorCode::INVALID_REQUEST, 'Invalid request', [
                        'command' => $command,
                    ], 400);
                }
                if (!$this->isArgAllowed($arg, $allowedArgs)) {
                    return ApiResponse::error(ErrorCode::RBAC_DENIED, 'Forbidden', [
                        'command' => $command,
                    ], 403);
                }
            }

            $steps[] = [
                'id' => 's' . ($index + 1),
                'title' => $command,
                'command' => $command,
                'args' => array_values(array_map('strval', $args)),
            ];
        }

        if ($steps === []) {
            return ApiResponse::error(ErrorCode::INVALID_REQUEST, 'Invalid request', [
                'steps' => 'empty',
            ], 400);
        }

        $plan = new Plan([
            'id' => bin2hex(random_bytes(16)),
            'created_at' => gmdate(DATE_ATOM),
            'kind' => 'tools.run',
            'summary' => 'AI tools dry-run',
            'steps' => $steps,
            'confidence' => 0.0,
            'risk' => 'low',
        ]);

        $started = microtime(true);
        $runner = new PlanRunner(dirname(__DIR__, 3));
        $result = $runner->run($plan, true, false);
        $durationMs = (microtime(true) - $started) * 1000;

        (new AuditLogger($this->db, $request->session()))->log(
            'ai.tools_run',
            'ai',
            null,
            [
                'steps_total' => (int) ($result['steps_total'] ?? 0),
                'steps_run' => (int) ($result['steps_run'] ?? 0),
                'failed' => (int) ($result['failed'] ?? 0),
                'duration_ms' => (int) $durationMs,
            ],
            (int) ($user['id'] ?? 0),
            $request->ip()
        );

        if ($this->isHtmx($request)) {
            return Response::html($this->renderRunHtml($result), 200);
        }

        return ApiResponse::ok([
            'steps_total' => (int) ($result['steps_total'] ?? 0),
            'steps_run' => (int) ($result['steps_run'] ?? 0),
            'failed' => (int) ($result['failed'] ?? 0),
            'outputs' => $result['outputs'] ?? [],
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
     * @return array<string, array<int, string>>
     */
    private function buildAllowMap(array $tools): array
    {
        $map = [];
        foreach ($tools as $tool) {
            if (!is_array($tool)) {
                continue;
            }
            $name = (string) ($tool['name'] ?? '');
            $args = $tool['args'] ?? [];
            if ($name === '' || !is_array($args)) {
                continue;
            }
            $map[$name] = array_values(array_map('strval', $args));
        }

        return $map;
    }

    /**
     * @param array<int, string> $allowedArgs
     */
    private function isArgAllowed(string $arg, array $allowedArgs): bool
    {
        if (str_starts_with($arg, '--')) {
            $key = $arg;
            $valuePos = strpos($arg, '=');
            if ($valuePos !== false) {
                $key = substr($arg, 0, $valuePos);
            }
            return in_array($key, $allowedArgs, true);
        }

        return false;
    }

    /**
     * @return array<string, mixed>
     */
    private function readJson(Request $request): array
    {
        $contentType = strtolower((string) ($request->getHeader('content-type') ?? ''));
        if (!str_contains($contentType, 'application/json')) {
            $rawPlan = $request->post('plan_json');
            if (is_string($rawPlan) && $rawPlan !== '') {
                $decoded = json_decode($rawPlan, true);
                if (is_array($decoded)) {
                    return ['plan' => $decoded];
                }
            }
            return [];
        }
        $raw = trim($request->getBody());
        if ($raw === '') {
            return [];
        }
        $data = json_decode($raw, true);
        return is_array($data) ? $data : [];
    }

    private function isHtmx(Request $request): bool
    {
        return strtolower((string) ($request->getHeader('hx-request') ?? '')) === 'true';
    }

    private function renderProposeHtml(array $result): string
    {
        $proposal = is_array($result['proposal'] ?? null) ? $result['proposal'] : [];
        $plan = is_array($result['plan'] ?? null) ? $result['plan'] : [];
        $proposalJson = json_encode($proposal, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}';
        $planJson = json_encode($plan, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}';

        $proposalEsc = htmlspecialchars($proposalJson, ENT_QUOTES);
        $planEsc = htmlspecialchars($planJson, ENT_QUOTES);
        $summary = htmlspecialchars((string) ($proposal['summary'] ?? ''), ENT_QUOTES);
        $risk = htmlspecialchars((string) ($proposal['risk'] ?? ''), ENT_QUOTES);
        $confidence = htmlspecialchars((string) ($proposal['confidence'] ?? ''), ENT_QUOTES);

        return '<div class="card mt-3">'
            . '<div class="card-body">'
            . '<h5 class="card-title mb-2">Proposal + Plan</h5>'
            . '<div class="text-muted small mb-2">summary=' . $summary
            . ' risk=' . $risk . ' confidence=' . $confidence . '</div>'
            . '<div class="row g-3">'
            . '<div class="col-lg-6"><div class="fw-semibold mb-1">Proposal</div><pre class="small mb-0">'
            . $proposalEsc . '</pre></div>'
            . '<div class="col-lg-6"><div class="fw-semibold mb-1">Plan</div><pre class="small mb-0">'
            . $planEsc . '</pre></div>'
            . '</div>'
            . '<textarea id="proposal_json" name="proposal_json" class="d-none">' . $proposalEsc . '</textarea>'
            . '<textarea id="plan_json" name="plan_json" class="d-none">' . $planEsc . '</textarea>'
            . '</div></div>';
    }

    private function renderRunHtml(array $result): string
    {
        $outputs = $result['outputs'] ?? [];
        $payload = [
            'steps_total' => (int) ($result['steps_total'] ?? 0),
            'steps_run' => (int) ($result['steps_run'] ?? 0),
            'failed' => (int) ($result['failed'] ?? 0),
            'outputs' => $outputs,
        ];
        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}';
        $jsonEsc = htmlspecialchars($json, ENT_QUOTES);

        return '<div class="card mt-3">'
            . '<div class="card-body">'
            . '<h5 class="card-title mb-2">Dry-run results</h5>'
            . '<pre class="small mb-0">' . $jsonEsc . '</pre>'
            . '</div></div>';
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
