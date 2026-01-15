<?php
declare(strict_types=1);

namespace Laas\Modules\Api\Controller;

use Laas\Api\ApiResponse;
use Laas\Ai\Context\AiContextBuilder;
use Laas\Ai\Provider\AiProviderInterface;
use Laas\Ai\Provider\LocalDemoProvider;
use Laas\Database\DatabaseManager;
use Laas\Http\ErrorCode;
use Laas\Http\Request;
use Laas\Http\Response;
use Laas\Support\AuditLogger;

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
        $provider = $this->resolveProvider($securityConfig);
        $providerName = (string) ($securityConfig['ai_provider'] ?? 'local_demo');

        $builder = new AiContextBuilder();
        $context = $builder->build($request);
        $prompt = (string) ($context['prompt'] ?? '');

        $result = $provider->propose($context);

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

    private function resolveProvider(array $securityConfig): AiProviderInterface
    {
        $name = strtolower((string) ($securityConfig['ai_provider'] ?? 'local_demo'));
        if ($name === 'local_demo') {
            return new LocalDemoProvider();
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
}
